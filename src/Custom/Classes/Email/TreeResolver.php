<?php

declare(strict_types=1);

namespace Espo\Custom\Classes\Email;

use Espo\Core\Utils\Config;
use Espo\Custom\Tools\LastContact\LastContactService;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PDO;
use Throwable;

/**
 * Hybrid Email Tree Resolver:
 * 1) Exact thread inheritance (repliedId) and exact number matching (Proposal, Case, Document);
 * 2) AI Semantic Router (Perplexity sonar) when an email belongs to a known Account and needs semantic matching + intent summary;
 * 3) Strict vertical branch expansion (Act <-> Invoice <-> Case <-> Proposal <-> Opportunity -> Appendix -> Contract -> Account).
 */
final class TreeResolver
{
    public function __construct(
        private EntityManager $entityManager,
        private ?Config $config = null
    ) {}

    public static function ensureTable(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS email_tree_link (
                email_id VARCHAR(24) NOT NULL,
                entity_type VARCHAR(50) NOT NULL,
                entity_id VARCHAR(24) NOT NULL,
                PRIMARY KEY (email_id, entity_type, entity_id),
                INDEX idx_entity (entity_type, entity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS email_ai_meta (
                email_id VARCHAR(24) NOT NULL PRIMARY KEY,
                intent VARCHAR(80) DEFAULT NULL,
                summary VARCHAR(255) DEFAULT NULL,
                needs_action TINYINT(1) NOT NULL DEFAULT 0,
                analyzed_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function linkEmail(Entity $email, bool $allowAi = true): void
    {
        $emailId = (string) ($email->getId() ?? '');
        if ($emailId === '') {
            return;
        }

        $pdo = $this->entityManager->getPDO();
        self::ensureTable($pdo);

        $nodes = [
            'Document' => [],
            'Case' => [],
            'Proposal' => [],
            'Opportunity' => [],
            'Account' => [],
        ];

        $parentType = (string) ($email->get('parentType') ?? '');
        $parentId = (string) ($email->get('parentId') ?? '');
        if ($parentType !== '' && $parentId !== '' && isset($nodes[$parentType])) {
            $nodes[$parentType][$parentId] = true;
        }

        $accountId = (string) ($email->get('accountId') ?? '');
        if ($accountId !== '') {
            $nodes['Account'][$accountId] = true;
        }

        // 1. Replied thread inheritance
        $repliedId = (string) ($email->get('repliedId') ?? '');
        if ($repliedId !== '') {
            $this->collectFromReplied($pdo, $repliedId, $nodes);
        }

        // Resolve sender/recipient accounts
        $addrAccountIds = $this->resolveAddressAccountIds($email);
        foreach ($addrAccountIds as $aid) {
            $nodes['Account'][$aid] = true;
        }

        // 2. Exact unique numbers in subject, attachment filenames, and body
        $subject = (string) ($email->get('name') ?? '');
        $body = (string) ($email->get('bodyPlain') ?: strip_tags((string) ($email->get('body') ?? '')));
        $attNames = $this->attachmentNames($pdo, $emailId);
        $haystack = trim($subject . "\n" . implode("\n", $attNames) . "\n" . mb_substr($body, 0, 12000));

        if ($haystack !== '') {
            $this->matchNumbers($pdo, $haystack, array_keys($nodes['Account']), $nodes);
        }

        // 3. AI Semantic Router & Intent Extractor (when Account is known)
        if ($allowAi && $nodes['Account'] !== []) {
            $this->runAiRouterIfNeeded($pdo, $email, $subject, $body, $attNames, array_keys($nodes['Account']), $nodes);
        }

        // 4. Expand strictly along the vertical hierarchy branch
        $this->expandVerticalBranch($pdo, $nodes);

        $this->persistLinks($pdo, $email, $nodes);
    }

    public function retroLinkForEntity(string $entityType, string $entityId): void
    {
        if ($entityId === '') {
            return;
        }
        $pdo = $this->entityManager->getPDO();
        self::ensureTable($pdo);

        $nodes = [
            'Document' => [],
            'Case' => [],
            'Proposal' => [],
            'Opportunity' => [],
            'Account' => [],
        ];
        if (!isset($nodes[$entityType])) {
            return;
        }
        $nodes[$entityType][$entityId] = true;
        $this->expandVerticalBranch($pdo, $nodes);

        $emailIds = [];
        foreach (['Case', 'Proposal', 'Opportunity'] as $type) {
            foreach (array_keys($nodes[$type]) as $id) {
                $s1 = $pdo->prepare("SELECT id FROM email WHERE deleted = 0 AND parent_type = ? AND parent_id = ?");
                $s1->execute([$type, $id]);
                foreach ($s1->fetchAll(PDO::FETCH_COLUMN) as $eid) {
                    $emailIds[(string) $eid] = true;
                }

                $s2 = $pdo->prepare("SELECT email_id FROM email_tree_link WHERE entity_type = ? AND entity_id = ?");
                $s2->execute([$type, $id]);
                foreach ($s2->fetchAll(PDO::FETCH_COLUMN) as $eid) {
                    $emailIds[(string) $eid] = true;
                }
            }
        }

        if ($emailIds === []) {
            return;
        }

        $ins = $pdo->prepare(
            "INSERT IGNORE INTO email_tree_link (email_id, entity_type, entity_id) VALUES (?, ?, ?)"
        );
        foreach (array_keys($emailIds) as $eid) {
            foreach ($nodes as $type => $list) {
                foreach (array_keys($list) as $id) {
                    $ins->execute([$eid, $type, $id]);
                }
            }
        }
    }

    /**
     * @param list<string> $attNames
     * @param list<string> $accountIds
     */
    private function runAiRouterIfNeeded(
        PDO $pdo,
        Entity $email,
        string $subject,
        string $body,
        array $attNames,
        array $accountIds,
        array &$nodes
    ): void {
        $emailId = (string) $email->getId();

        // Check if already analyzed in email_ai_meta
        $chk = $pdo->prepare("SELECT intent, summary FROM email_ai_meta WHERE email_id = ? LIMIT 1");
        $chk->execute([$emailId]);
        $existingMeta = $chk->fetch(PDO::FETCH_ASSOC);

        $hasSpecificNode = $nodes['Case'] !== [] || $nodes['Proposal'] !== [] || $nodes['Opportunity'] !== [] || $nodes['Document'] !== [];
        if ($existingMeta !== false && $hasSpecificNode) {
            return;
        }

        $apiKey = $this->resolveApiKey();
        if ($apiKey === '') {
            return;
        }

        $in = implode(',', array_map(static fn ($id) => $pdo->quote($id), $accountIds));
        $accNames = $pdo->query("SELECT name FROM account WHERE id IN ({$in}) AND deleted = 0")->fetchAll(PDO::FETCH_COLUMN);

        $candidates = [];
        foreach ($pdo->query("SELECT id, name, stage FROM opportunity WHERE deleted = 0 AND account_id IN ({$in})")->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $candidates[] = ['type' => 'Opportunity', 'id' => (string) $o['id'], 'title' => (string) $o['name'], 'status' => (string) ($o['stage'] ?? '')];
        }
        foreach ($pdo->query("SELECT id, name, work_title, status FROM proposal WHERE deleted = 0 AND account_id IN ({$in})")->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $candidates[] = ['type' => 'Proposal', 'id' => (string) $p['id'], 'title' => (string) $p['name'], 'status' => (string) ($p['status'] ?? '')];
        }
        foreach ($pdo->query("SELECT id, name, work_title, status FROM `case` WHERE deleted = 0 AND account_id IN ({$in})")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $candidates[] = ['type' => 'Case', 'id' => (string) $c['id'], 'title' => (string) $c['name'], 'status' => (string) ($c['status'] ?? '')];
        }
        foreach ($pdo->query("SELECT id, name, type, doc_number FROM document WHERE deleted = 0 AND counterparty_id IN ({$in}) AND type IN ('Contract', 'Приложение', 'Счет авансовый', 'Счет', 'Акт')")->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $candidates[] = ['type' => 'Document', 'id' => (string) $d['id'], 'title' => (string) $d['name'], 'status' => (string) ($d['type'] ?? '')];
        }

        if ($candidates === [] && $existingMeta !== false) {
            return;
        }

        $cleanBody = preg_replace('/\s+/u', ' ', mb_substr(trim($body), 0, 900)) ?? '';
        $prompt = "Проанализируй деловое письмо в CRM инженерной компании RD[AI] и определи:\n"
            . "1) К каким конкретно сделкам (Opportunity), КП (Proposal), заказам (Case) или документам (Document) этого клиента оно относится по смыслу или номеру (выбирай только при смысловом совпадении confidence >= 0.80; не выбирай несвязанные проекты!).\n"
            . "2) Категорию интента (intent): строго один из [\"Согласование / Оплата\", \"Исходные данные / ТЗ\", \"Вопрос / Правки\", \"Отправка КП / Документов\", \"ЭДО / Подписание\", \"Инфо / Прочее\"].\n"
            . "3) Краткую суть в 1 предложении (summary, до 110 символов).\n"
            . "4) Требуется ли действие/ответ (needsAction: true/false).\n\n"
            . "КЛИЕНТ: " . implode(', ', $accNames) . "\n"
            . "КАНДИДАТЫ В CRM ПО ЭТОМУ КЛИЕНТУ:\n" . json_encode($candidates, JSON_UNESCAPED_UNICODE) . "\n\n"
            . "ПИСЬМО:\n"
            . "Тема: " . $subject . "\n"
            . "Вложения: " . implode(', ', $attNames) . "\n"
            . "Текст: " . $cleanBody;

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['matched', 'intent', 'summary', 'needsAction'],
            'properties' => [
                'matched' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['type', 'id', 'confidence'],
                        'properties' => [
                            'type' => ['type' => 'string'],
                            'id' => ['type' => 'string'],
                            'confidence' => ['type' => 'number'],
                        ],
                    ],
                ],
                'intent' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'needsAction' => ['type' => 'boolean'],
            ],
        ];

        try {
            $ch = curl_init('https://api.perplexity.ai/chat/completions');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'model' => 'sonar',
                    'temperature' => 0.1,
                    'max_tokens' => 500,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'Ты — точный ИИ-маршрутизатор B2B CRM. Привязывай письмо к сущности только при явном смысловом совпадении (confidence >= 0.80). Отвечай строго JSON.',
                        ],
                        ['role' => 'user', 'content' => $prompt],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => ['name' => 'email_ai_route', 'schema' => $schema],
                    ],
                ], JSON_UNESCAPED_UNICODE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json',
                ],
            ]);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($raw === false || $code < 200 || $code >= 300) {
                return;
            }

            $resp = json_decode((string) $raw, true);
            $content = (string) ($resp['choices'][0]['message']['content'] ?? '{}');
            $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($content)) ?? '';
            $ai = json_decode($content, true);
            if (!is_array($ai)) {
                return;
            }

            $validCandidateMap = [];
            foreach ($candidates as $cand) {
                $validCandidateMap[$cand['type'] . ':' . $cand['id']] = true;
            }

            foreach (($ai['matched'] ?? []) as $m) {
                if (!is_array($m)) {
                    continue;
                }
                $t = (string) ($m['type'] ?? '');
                $id = (string) ($m['id'] ?? '');
                $conf = (float) ($m['confidence'] ?? 0);
                if ($conf >= 0.80 && isset($nodes[$t]) && isset($validCandidateMap[$t . ':' . $id])) {
                    $nodes[$t][$id] = true;
                }
            }

            $intent = mb_substr(trim((string) ($ai['intent'] ?? '')), 0, 80);
            $summary = mb_substr(trim((string) ($ai['summary'] ?? '')), 0, 250);
            $needsAction = !empty($ai['needsAction']) ? 1 : 0;

            $insMeta = $pdo->prepare(
                "INSERT INTO email_ai_meta (email_id, intent, summary, needs_action, analyzed_at)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE intent = VALUES(intent), summary = VALUES(summary), needs_action = VALUES(needs_action), analyzed_at = UTC_TIMESTAMP()"
            );
            $insMeta->execute([$emailId, $intent ?: null, $summary ?: null, $needsAction]);
        } catch (Throwable) {
        }
    }

    private function resolveApiKey(): string
    {
        if ($this->config !== null) {
            $k = trim((string) $this->config->get('perplexityApiKey'));
            if ($k !== '') {
                return $k;
            }
        }
        $cfgFile = '/var/www/html/data/config.php';
        if (is_file($cfgFile)) {
            $c = include $cfgFile;
            if (is_array($c) && !empty($c['perplexityApiKey'])) {
                return trim((string) $c['perplexityApiKey']);
            }
        }
        return '';
    }

    private function collectFromReplied(PDO $pdo, string $repliedId, array &$nodes): void
    {
        $stmt = $pdo->prepare("SELECT parent_type, parent_id, account_id FROM email WHERE id = ? AND deleted = 0 LIMIT 1");
        $stmt->execute([$repliedId]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $pt = (string) ($row['parent_type'] ?? '');
            $pid = (string) ($row['parent_id'] ?? '');
            if ($pt !== '' && $pid !== '' && isset($nodes[$pt])) {
                $nodes[$pt][$pid] = true;
            }
            if (!empty($row['account_id'])) {
                $nodes['Account'][(string) $row['account_id']] = true;
            }
        }

        $tStmt = $pdo->prepare("SELECT entity_type, entity_id FROM email_tree_link WHERE email_id = ?");
        $tStmt->execute([$repliedId]);
        foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $et = (string) ($r['entity_type'] ?? '');
            $eid = (string) ($r['entity_id'] ?? '');
            if (isset($nodes[$et]) && $eid !== '') {
                $nodes[$et][$eid] = true;
            }
        }
    }

    private function attachmentNames(PDO $pdo, string $emailId): array
    {
        $stmt = $pdo->prepare("SELECT name FROM attachment WHERE deleted = 0 AND related_type = 'Email' AND related_id = ?");
        $stmt->execute([$emailId]);
        return array_values(array_filter(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    private function resolveAddressAccountIds(Entity $email): array
    {
        $service = new LastContactService($this->entityManager);
        $rawList = [
            (string) ($email->get('from') ?? ''),
            (string) ($email->get('to') ?? ''),
            (string) ($email->get('cc') ?? ''),
        ];
        $addrs = LastContactService::extractAddresses($rawList);
        if ($addrs === []) {
            return [];
        }

        $accountIds = [];
        foreach ($service->accountsForAddresses($addrs, true) as $accMap) {
            foreach (array_keys($accMap) as $aid) {
                $accountIds[(string) $aid] = true;
            }
        }

        return array_keys($accountIds);
    }

    private function matchNumbers(PDO $pdo, string $haystack, array $accountIds, array &$nodes): void
    {
        foreach ($pdo->query("SELECT id, proposal_number, account_id FROM proposal WHERE deleted = 0 AND proposal_number IS NOT NULL AND proposal_number <> ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $num = trim((string) $r['proposal_number']);
            if (mb_strlen($num) >= 5 && $this->containsToken($haystack, $num)) {
                if ($accountIds === [] || empty($r['account_id']) || in_array((string) $r['account_id'], $accountIds, true)) {
                    $nodes['Proposal'][(string) $r['id']] = true;
                }
            }
        }

        foreach ($pdo->query("SELECT id, order_number, account_id FROM `case` WHERE deleted = 0 AND order_number IS NOT NULL AND order_number <> ''")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $num = trim((string) $r['order_number']);
            if (mb_strlen($num) >= 4 && $this->containsToken($haystack, $num)) {
                if ($accountIds === [] || empty($r['account_id']) || in_array((string) $r['account_id'], $accountIds, true)) {
                    $nodes['Case'][(string) $r['id']] = true;
                }
            }
        }

        $docSql = "SELECT id, doc_number, counterparty_id FROM document WHERE deleted = 0 AND doc_number IS NOT NULL AND doc_number <> ''";
        foreach ($pdo->query($docSql)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $num = trim((string) $r['doc_number']);
            if (mb_strlen($num) < 4) {
                continue;
            }
            $altNum = str_replace('/', '-', $num);
            if ($this->containsToken($haystack, $num) || ($altNum !== $num && $this->containsToken($haystack, $altNum))) {
                if ($accountIds === [] || empty($r['counterparty_id']) || in_array((string) $r['counterparty_id'], $accountIds, true)) {
                    $nodes['Document'][(string) $r['id']] = true;
                }
            }
        }
    }

    private function containsToken(string $haystack, string $token): bool
    {
        $q = preg_quote($token, '/');
        return preg_match('/(?<![\p{L}\p{N}\/\-])' . $q . '(?![\p{L}\p{N}\/\-])/iu', $haystack) === 1;
    }

    private function expandVerticalBranch(PDO $pdo, array &$nodes): void
    {
        foreach (array_keys($nodes['Document']) as $docId) {
            $s = $pdo->prepare(
                "SELECT type, contract_id, appendix_id, work_order_id, counterparty_id
                 FROM document WHERE id = ? AND deleted = 0"
            );
            $s->execute([$docId]);
            if ($r = $s->fetch(PDO::FETCH_ASSOC)) {
                $type = (string) ($r['type'] ?? '');
                if (!empty($r['work_order_id'])) {
                    $nodes['Case'][(string) $r['work_order_id']] = true;
                }
                if (!empty($r['counterparty_id'])) {
                    $nodes['Account'][(string) $r['counterparty_id']] = true;
                }
                if (in_array($type, ['Счет авансовый', 'Счет', 'Акт'], true)) {
                    $m = $pdo->prepare("SELECT case_id FROM document_case_order WHERE deleted = 0 AND document_id = ?");
                    $m->execute([$docId]);
                    foreach ($m->fetchAll(PDO::FETCH_COLUMN) as $cid) {
                        $nodes['Case'][(string) $cid] = true;
                    }
                    try {
                        $ai = $pdo->prepare("SELECT left_id, right_id FROM act_invoice WHERE deleted = 0 AND (left_id = ? OR right_id = ?)");
                        $ai->execute([$docId, $docId]);
                        foreach ($ai->fetchAll(PDO::FETCH_ASSOC) as $pair) {
                            if (!empty($pair['left_id'])) {
                                $nodes['Document'][(string) $pair['left_id']] = true;
                            }
                            if (!empty($pair['right_id'])) {
                                $nodes['Document'][(string) $pair['right_id']] = true;
                            }
                        }
                    } catch (Throwable) {
                    }
                }
            }
        }

        for ($i = 0; $i < 2; $i++) {
            foreach (array_keys($nodes['Proposal']) as $propId) {
                $s = $pdo->prepare("SELECT opportunity_id, work_order_id, account_id FROM proposal WHERE id = ? AND deleted = 0");
                $s->execute([$propId]);
                if ($r = $s->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($r['opportunity_id'])) {
                        $nodes['Opportunity'][(string) $r['opportunity_id']] = true;
                    }
                    if (!empty($r['work_order_id'])) {
                        $nodes['Case'][(string) $r['work_order_id']] = true;
                    }
                    if (!empty($r['account_id'])) {
                        $nodes['Account'][(string) $r['account_id']] = true;
                    }
                }
            }

            foreach (array_keys($nodes['Opportunity']) as $oppId) {
                $s = $pdo->prepare("SELECT account_id FROM opportunity WHERE id = ? AND deleted = 0");
                $s->execute([$oppId]);
                if ($r = $s->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($r['account_id'])) {
                        $nodes['Account'][(string) $r['account_id']] = true;
                    }
                }
                $cs = $pdo->prepare("SELECT id FROM `case` WHERE deleted = 0 AND opportunity_id = ?");
                $cs->execute([$oppId]);
                foreach ($cs->fetchAll(PDO::FETCH_COLUMN) as $cid) {
                    $nodes['Case'][(string) $cid] = true;
                }
                $ps = $pdo->prepare("SELECT id FROM proposal WHERE deleted = 0 AND opportunity_id = ?");
                $ps->execute([$oppId]);
                foreach ($ps->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                    $nodes['Proposal'][(string) $pid] = true;
                }
            }

            foreach (array_keys($nodes['Case']) as $caseId) {
                $s = $pdo->prepare("SELECT opportunity_id, account_id FROM `case` WHERE id = ? AND deleted = 0");
                $s->execute([$caseId]);
                if ($r = $s->fetch(PDO::FETCH_ASSOC)) {
                    if (!empty($r['opportunity_id'])) {
                        $nodes['Opportunity'][(string) $r['opportunity_id']] = true;
                    }
                    if (!empty($r['account_id'])) {
                        $nodes['Account'][(string) $r['account_id']] = true;
                    }
                }
                $ps = $pdo->prepare("SELECT id FROM proposal WHERE deleted = 0 AND work_order_id = ?");
                $ps->execute([$caseId]);
                foreach ($ps->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                    $nodes['Proposal'][(string) $pid] = true;
                }
            }
        }

        foreach (array_keys($nodes['Case']) as $caseId) {
            $s = $pdo->prepare("SELECT contract_id, appendix_id FROM `case` WHERE id = ? AND deleted = 0");
            $s->execute([$caseId]);
            if ($r = $s->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($r['contract_id'])) {
                    $nodes['Document'][(string) $r['contract_id']] = true;
                }
                if (!empty($r['appendix_id'])) {
                    $nodes['Document'][(string) $r['appendix_id']] = true;
                }
            }

            $ds = $pdo->prepare(
                "SELECT id FROM document WHERE deleted = 0 AND work_order_id = ?
                 UNION
                 SELECT document_id FROM document_case_order WHERE deleted = 0 AND case_id = ?"
            );
            $ds->execute([$caseId, $caseId]);
            foreach ($ds->fetchAll(PDO::FETCH_COLUMN) as $did) {
                $nodes['Document'][(string) $did] = true;
            }
        }

        foreach (array_keys($nodes['Document']) as $docId) {
            $s = $pdo->prepare("SELECT contract_id, appendix_id, counterparty_id FROM document WHERE id = ? AND deleted = 0");
            $s->execute([$docId]);
            if ($r = $s->fetch(PDO::FETCH_ASSOC)) {
                if (!empty($r['contract_id'])) {
                    $nodes['Document'][(string) $r['contract_id']] = true;
                }
                if (!empty($r['appendix_id'])) {
                    $nodes['Document'][(string) $r['appendix_id']] = true;
                }
                if (!empty($r['counterparty_id'])) {
                    $nodes['Account'][(string) $r['counterparty_id']] = true;
                }
            }
        }
    }

    private function persistLinks(PDO $pdo, Entity $email, array $nodes): void
    {
        $emailId = (string) $email->getId();
        $ins = $pdo->prepare(
            "INSERT IGNORE INTO email_tree_link (email_id, entity_type, entity_id) VALUES (?, ?, ?)"
        );

        foreach ($nodes as $type => $list) {
            foreach (array_keys($list) as $id) {
                if ($id !== '') {
                    $ins->execute([$emailId, $type, $id]);
                }
            }
        }

        $curParentType = (string) ($email->get('parentType') ?? '');
        $curParentId = (string) ($email->get('parentId') ?? '');
        $newParentType = $curParentType;
        $newParentId = $curParentId;

        if ($curParentId === '' || in_array($curParentType, ['Account', 'Contact'], true)) {
            if ($nodes['Case'] !== []) {
                $newParentType = 'Case';
                $newParentId = (string) array_key_first($nodes['Case']);
            } elseif ($nodes['Proposal'] !== []) {
                $newParentType = 'Proposal';
                $newParentId = (string) array_key_first($nodes['Proposal']);
            } elseif ($nodes['Opportunity'] !== []) {
                $newParentType = 'Opportunity';
                $newParentId = (string) array_key_first($nodes['Opportunity']);
            } elseif ($nodes['Document'] !== []) {
                $newParentType = 'Document';
                $newParentId = (string) array_key_first($nodes['Document']);
            }
        }

        $accountId = (string) ($email->get('accountId') ?? '');
        if ($accountId === '' && $nodes['Account'] !== []) {
            $accountId = (string) array_key_first($nodes['Account']);
        }

        if ($newParentType !== $curParentType || $newParentId !== $curParentId || ($accountId !== '' && $accountId !== (string) $email->get('accountId'))) {
            $upd = $pdo->prepare(
                "UPDATE email SET parent_type = ?, parent_id = ?, account_id = COALESCE(NULLIF(account_id, ''), ?) WHERE id = ?"
            );
            $upd->execute([
                $newParentType !== '' ? $newParentType : null,
                $newParentId !== '' ? $newParentId : null,
                $accountId !== '' ? $accountId : null,
                $emailId,
            ]);
            $email->set([
                'parentType' => $newParentType !== '' ? $newParentType : null,
                'parentId' => $newParentId !== '' ? $newParentId : null,
            ]);
            if ($accountId !== '') {
                $email->set('accountId', $accountId);
            }
        }
    }
}
