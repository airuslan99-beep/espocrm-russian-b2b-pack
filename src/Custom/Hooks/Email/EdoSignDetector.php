<?php

declare(strict_types=1);

namespace Espo\Custom\Hooks\Email;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Custom\Classes\Document\Format;
use Espo\Custom\Classes\Document\Numbering;
use Espo\Custom\Classes\Document\Types;
use Espo\Custom\Classes\Telegram\Api as TelegramApi;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use PDO;
use Throwable;

/**
 * Automatically detects signed Acts, Appendices, and Contracts from incoming emails
 * (including notifications sent to invoice@rdai.pro / director@rdai.pro from Tochka EDO,
 * Kaluga Astral, Diadoc, SBIS, or customer replies with .sig/.p7s detached signatures).
 */
class EdoSignDetector implements AfterSave
{
    public static int $order = 35;

    private const SIGN_PATTERNS = [
        '/документ[^.\n]{0,80}подписан/iu',
        '/подписал[аи]?\s+документ/iu',
        '/документооборот[^.\n]{0,60}заверш[её]н/iu',
        '/получена\s+подпись/iu',
        '/подписанный\s+(?:акт|договор|скан|экземпляр)/iu',
        '/акт[^.\n]{0,60}подписан/iu',
        '/договор[^.\n]{0,60}подписан/iu',
    ];

    private const REJECT_PATTERNS = [
        '/отказ[^.\n]{0,40}подпис/iu',
        '/отклонен/iu',
        '/аннулирован/iu',
        '/требуется\s+уточнение/iu',
        '/не\s+подписан/iu',
    ];

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Log $log
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->isNew() && !$entity->isAttributeChanged('status')) {
            return;
        }

        if ($entity->get('status') !== 'Archived') {
            return;
        }

        try {
            $this->inspectEmail($entity);
        } catch (Throwable $e) {
            $this->log->error('EdoSignDetector [' . $entity->getId() . ']: ' . $e->getMessage());
        }
    }

    private function inspectEmail(Entity $email): void
    {
        $from = mb_strtolower(trim((string) ($email->get('from') ?: $email->get('fromAddress') ?: '')));
        if (str_ends_with($from, '@rdai.pro') && !str_starts_with($from, 'edo@') && !str_starts_with($from, 'noreply@')) {
            // Skip our own outgoing messages unless forwarded to invoice@rdai.pro
            $to = mb_strtolower((string) ($email->get('to') ?? ''));
            if (!str_contains($to, 'invoice@rdai.pro')) {
                return;
            }
        }

        $subject = trim((string) ($email->get('name') ?? ''));
        $bodyPlain = trim((string) ($email->get('bodyPlain') ?? ''));
        if ($bodyPlain === '') {
            $bodyPlain = trim(strip_tags((string) ($email->get('body') ?? '')));
        }

        $fullText = $subject . "\n" . $bodyPlain;

        foreach (self::REJECT_PATTERNS as $rej) {
            if (preg_match($rej, $fullText) === 1) {
                return;
            }
        }

        $hasSigAttachment = $this->hasSignatureAttachment((string) $email->getId());
        $hasSignedText = false;

        foreach (self::SIGN_PATTERNS as $pat) {
            if (preg_match($pat, $fullText) === 1) {
                $hasSignedText = true;
                break;
            }
        }

        if (!$hasSignedText && !$hasSigAttachment) {
            return;
        }

        $unsignedDocs = $this->loadUnsignedDocuments();
        if ($unsignedDocs === []) {
            return;
        }

        $matchedDoc = $this->matchDocument($fullText, $email, $unsignedDocs);
        if ($matchedDoc === null) {
            return;
        }

        $docEntity = $this->entityManager->getEntityById('Document', (string) $matchedDoc['id']);
        if ($docEntity === null || $docEntity->get('signStatus') === 'Подписан') {
            return;
        }

        $today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Moscow')))->format('Y-m-d');
        $docEntity->set([
            'signStatus' => 'Подписан',
            'signedDate' => $docEntity->get('signedDate') ?: $today,
        ]);
        $this->entityManager->saveEntity($docEntity);

        $this->notifyTelegram($docEntity, $email, $hasSigAttachment);
    }

    private function hasSignatureAttachment(string $emailId): bool
    {
        if ($emailId === '') {
            return false;
        }

        $stmt = $this->entityManager->getPDO()->prepare(
            "SELECT name FROM attachment WHERE deleted = 0 AND related_type = 'Email' AND related_id = ?"
        );
        $stmt->execute([$emailId]);

        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $n = mb_strtolower(trim((string) $name));
            if (
                str_ends_with($n, '.sig') ||
                str_ends_with($n, '.p7s') ||
                str_ends_with($n, '.sgn') ||
                preg_match('/^(on_nschfdoppr|on_act|dp_izvuch|on_dog)/i', $n) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadUnsignedDocuments(): array
    {
        $stmt = $this->entityManager->getPDO()->prepare(
            "SELECT d.id, d.name, d.type, d.doc_number, d.counterparty_id, d.work_order_id, a.name AS account_name, a.c_inn, a.inn
             FROM document d
             LEFT JOIN account a ON a.id = d.counterparty_id AND a.deleted = 0
             WHERE d.deleted = 0
               AND d.type IN (?, ?, ?)
               AND (d.sign_status IS NULL OR d.sign_status <> 'Подписан')
               AND (d.status IS NULL OR d.status <> 'Canceled')
             ORDER BY d.doc_date DESC, d.created_at DESC"
        );
        $stmt->execute([Types::ACT, Types::CONTRACT, Types::APPENDIX]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<array<string, mixed>> $docs
     * @return ?array<string, mixed>
     */
    private function matchDocument(string $text, Entity $email, array $docs): ?array
    {
        // 1. Exact match by RD Act number or Contract number in email subject/body
        foreach ($docs as $d) {
            $num = trim((string) ($d['doc_number'] ?? ''));
            if ($num === '') {
                continue;
            }

            if ($d['type'] === Types::ACT && Numbering::mentionsInvoice($text, $num) === true) {
                return $d;
            }

            if (mb_strlen($num) >= 4 && mb_stripos($text, $num) !== false) {
                return $d;
            }
        }

        // 2. Match by email parent (Case / Account) when there is a single unsigned Act for that Order
        $parentType = (string) ($email->get('parentType') ?? '');
        $parentId = (string) ($email->get('parentId') ?? '');

        if ($parentType === 'Case' && $parentId !== '') {
            $byOrder = array_values(array_filter(
                $docs,
                static fn (array $d): bool => (string) ($d['work_order_id'] ?? '') === $parentId && $d['type'] === Types::ACT
            ));
            if (count($byOrder) === 1) {
                return $byOrder[0];
            }
        }

        // 3. Match by INN mentioned in EDO email when counterparty has exactly 1 unsigned Act
        foreach ($docs as $d) {
            $inn = trim((string) (($d['c_inn'] ?? '') ?: ($d['inn'] ?? '')));
            if ($inn !== '' && strlen($inn) >= 10 && str_contains($text, $inn)) {
                $sameInnActs = array_values(array_filter(
                    $docs,
                    static fn (array $x): bool =>
                        (string) ($x['counterparty_id'] ?? '') === (string) ($d['counterparty_id'] ?? '') &&
                        $x['type'] === Types::ACT
                ));
                if (count($sameInnActs) === 1) {
                    return $sameInnActs[0];
                }
            }
        }

        return null;
    }

    private function notifyTelegram(Entity $doc, Entity $email, bool $fromSigFile): void
    {
        $tg = new TelegramApi($this->config, $this->log);
        $chat = $tg->chatId();

        if (!$tg->isConfigured() || !is_numeric($chat) || (int) $chat <= 0) {
            return;
        }

        $sourceLabel = $fromSigFile ? 'файл электронной подписи в письме' : 'уведомление ЭДО / ответ на почту';
        $text = "✍️ <b>Документ автоматически отмечен подписанным</b>\n\n"
            . 'Документ: <b>' . Format::escape((string) $doc->get('name')) . "</b>\n"
            . 'Основание: ' . Format::escape($sourceLabel) . "\n"
            . 'Письмо: ' . Format::escape((string) ($email->get('name') ?: 'без темы'))
            . Format::link($this->config, 'Document', (string) $doc->getId(), 'Открыть документ');

        $tg->call('sendMessage', [
            'chat_id' => $chat,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], 15, false);
    }
}
