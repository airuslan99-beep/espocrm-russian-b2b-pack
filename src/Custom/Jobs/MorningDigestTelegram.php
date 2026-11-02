<?php

declare(strict_types=1);

namespace Espo\Custom\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Custom\Classes\Document\Format;
use Espo\Custom\Classes\Telegram\Api;
use Espo\ORM\EntityManager;
use PDO;
use RuntimeException;

class MorningDigestTelegram implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Log $log
    ) {}

    public function run(): void
    {
        $api = new Api($this->config, $this->log);
        $chat = $api->chatId();

        if (!$api->isConfigured() || !is_numeric($chat) || (int) $chat <= 0) {
            throw new RuntimeException('MorningDigestTelegram: Telegram chat is not configured.');
        }

        $res = $api->call('sendMessage', [
            'chat_id' => $chat,
            'text' => $this->buildDigestText(),
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], 15, true);

        if ($res === null) {
            throw new RuntimeException('MorningDigestTelegram: failed to send message.');
        }
    }

    public function buildDigestText(): string
    {
        $tz = new DateTimeZone('Europe/Moscow');
        $now = new DateTimeImmutable('now', $tz);
        $today = $now->format('Y-m-d');
        $yesterday = $now->modify('-1 day')->format('Y-m-d');
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');

        $pdo = $this->entityManager->getPDO();
        $site = rtrim((string) ($this->config->get('siteUrl') ?: 'https://crm.rdai.pro'), '/');

        $lines = [];
        $lines[] = '💰 <b>Деньги и счета</b>';

        $balances = $pdo->query(
            "SELECT balance_available FROM bank_account WHERE deleted = 0"
        )->fetchAll(PDO::FETCH_COLUMN);

        if ($balances !== []) {
            $totalBal = array_sum(array_map('floatval', $balances));
            $lines[] = '• На счетах в Точке: <b>' . Format::money($totalBal) . '</b>';
        }

        $inStmt = $pdo->prepare(
            "SELECT amount, counterparty_name FROM bank_operation
             WHERE deleted = 0 AND direction = 'Credit' AND operation_date >= ?
               AND (cashflow_category IS NULL OR cashflow_category NOT IN ('Перевод между своими счетами', 'Пополнение собственными средствами'))
             ORDER BY operation_date DESC, created_at DESC LIMIT 5"
        );
        $inStmt->execute([$yesterday]);
        $incomes = $inStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($incomes !== []) {
            $inSum = array_sum(array_map(static fn ($x) => (float) ($x['amount'] ?? 0), $incomes));
            $lines[] = '• Поступлений за вчера: <b>+' . Format::money($inSum) . '</b>';
            foreach ($incomes as $inc) {
                $lines[] = sprintf(
                    '   ↳ <b>+%s</b> — %s',
                    Format::money($inc['amount']),
                    Format::escape(mb_substr((string) ($inc['counterparty_name'] ?: 'Контрагент'), 0, 45))
                );
            }
        } else {
            $lines[] = '• Поступлений за вчера: <b>0,00 ₽</b>';
        }

        $invRows = $pdo->query(
            "SELECT d.id, d.name, d.doc_number, d.amount, d.paid_amount, d.payment_due_date, a.name AS account_name
             FROM document d
             LEFT JOIN account a ON a.id = d.counterparty_id AND a.deleted = 0
             WHERE d.deleted = 0 AND d.type IN ('Счет авансовый', 'Счет')
               AND (d.payment_status IS NULL OR d.payment_status <> 'Оплачен')
               AND (d.status IS NULL OR d.status <> 'Canceled')
             ORDER BY d.payment_due_date ASC, d.created_at ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        $totalReceivable = 0.0;
        $overdueList = [];
        $dueSoonList = [];

        foreach ($invRows as $inv) {
            $rem = max(0.0, (float) ($inv['amount'] ?? 0) - (float) ($inv['paid_amount'] ?? 0));
            $totalReceivable += $rem;
            $due = (string) ($inv['payment_due_date'] ?? '');
            if ($due !== '' && $due < $today) {
                $overdueList[] = [$inv, $rem, $due];
            } elseif ($due !== '' && $due <= $tomorrow) {
                $dueSoonList[] = [$inv, $rem, $due];
            }
        }

        if ($totalReceivable > 0) {
            $lines[] = sprintf('• Ожидаем оплат по выставленным счетам: <b>%s</b>', Format::money($totalReceivable));
        }

        foreach (array_slice($overdueList, 0, 5) as [$inv, $rem, $due]) {
            $dueDt = DateTimeImmutable::createFromFormat('!Y-m-d', $due, $tz);
            $days = $dueDt ? (int) $dueDt->diff($now)->days : 0;
            $url = $site . '/#Document/view/' . $inv['id'];
            $lines[] = sprintf(
                '   ⚠️ <a href="%s">Счёт № %s</a> (%s) — <b>%s</b> (просрочка %d дн.)',
                htmlspecialchars($url, ENT_QUOTES),
                Format::escape((string) ($inv['doc_number'] ?: $inv['name'])),
                Format::escape(mb_substr((string) ($inv['account_name'] ?: '—'), 0, 30)),
                Format::money($rem),
                $days
            );
        }

        foreach (array_slice($dueSoonList, 0, 5) as [$inv, $rem, $due]) {
            $url = $site . '/#Document/view/' . $inv['id'];
            $whenLabel = $due === $today ? 'срок сегодня' : 'срок завтра';
            $lines[] = sprintf(
                '   ⏳ <a href="%s">Счёт № %s</a> (%s) — <b>%s</b> (%s)',
                htmlspecialchars($url, ENT_QUOTES),
                Format::escape((string) ($inv['doc_number'] ?: $inv['name'])),
                Format::escape(mb_substr((string) ($inv['account_name'] ?: '—'), 0, 30)),
                Format::money($rem),
                $whenLabel
            );
        }

        $unsignedActs = $pdo->query(
            "SELECT amount FROM document
             WHERE deleted = 0 AND type = 'Акт'
               AND (sign_status IS NULL OR sign_status <> 'Подписан')
               AND (status IS NULL OR status <> 'Canceled')"
        )->fetchAll(PDO::FETCH_COLUMN);

        if ($unsignedActs !== []) {
            $lines[] = sprintf(
                '• Акты ждут подписи клиента: %d шт. на <b>%s</b>',
                count($unsignedActs),
                Format::money(array_sum(array_map('floatval', $unsignedActs)))
            );
        }

        // 2. СТАТИСТИКА ПО ЗАКАЗАМ
        $lines[] = '';
        $lines[] = '📦 <b>Заказы (статистика)</b>';

        $actRow = $pdo->query(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS plan_sum
             FROM `case` WHERE deleted = 0 AND status NOT IN ('Выполнено', 'Отменено')"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $allOrdersTotals = $pdo->query(
            "SELECT COALESCE(SUM(paid_amount), 0) AS paid,
                    COALESCE(SUM(cost_amount), 0) AS cost,
                    COALESCE(SUM(gross_profit), 0) AS profit
             FROM `case` WHERE deleted = 0 AND status <> 'Отменено'"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $paidTotal = (float) ($allOrdersTotals['paid'] ?? 0);
        $costTotal = (float) ($allOrdersTotals['cost'] ?? 0);
        $profitTotal = (float) ($allOrdersTotals['profit'] ?? 0);

        $lines[] = sprintf(
            '• В работе: %d зак. на сумму <b>%s</b>',
            (int) ($actRow['cnt'] ?? 0),
            Format::money($actRow['plan_sum'] ?? 0)
        );
        $sProfit = Format::money($profitTotal);
        $sPaid   = Format::money($paidTotal);
        $sCost   = Format::money($costTotal);
        $maxLen  = max(mb_strlen($sProfit), mb_strlen($sPaid), mb_strlen($sCost));
        $pad = static fn (string $v): string => str_repeat("\u{2007}", max(0, $maxLen - mb_strlen($v))) . $v;

        $lines[] = '• Валовая прибыль по заказам (от оплат):';
        $lines[] = '   <b>' . $pad($sProfit) . '</b> — прибыль';
        $lines[] = '   <b>' . $pad($sPaid)   . '</b> — оплачено';
        $lines[] = '   <b>' . $pad($sCost)   . '</b> — себестоимость';

        // 3. ПЛАН НА СЕГОДНЯ
        $lines[] = '';
        $lines[] = '📅 <b>План на сегодня</b>';

        $tasks = $pdo->prepare(
            "SELECT id, name, parent_type, parent_id, account_id, contact_id,
                    DATE(CONVERT_TZ(date_end, '+00:00', '+03:00')) AS due_day, date_end_date
             FROM task WHERE deleted = 0 AND status NOT IN ('Completed', 'Canceled')
               AND ((date_end_date IS NOT NULL AND date_end_date <= :t1)
                 OR (date_end IS NOT NULL AND DATE(CONVERT_TZ(date_end, '+00:00', '+03:00')) <= :t2))
             ORDER BY date_end ASC, created_at ASC LIMIT 8"
        );
        $tasks->execute([':t1' => $today, ':t2' => $today]);
        $taskRows = $tasks->fetchAll(PDO::FETCH_ASSOC);

        $meetings = $pdo->prepare(
            "SELECT id, name, parent_type, parent_id, account_id,
                    TIME_FORMAT(CONVERT_TZ(date_start, '+00:00', '+03:00'), '%H:%i') AS tm
             FROM meeting WHERE deleted = 0 AND status = 'Planned'
               AND DATE(CONVERT_TZ(date_start, '+00:00', '+03:00')) = :today
             ORDER BY date_start ASC LIMIT 5"
        );
        $meetings->execute([':today' => $today]);
        $meetingRows = $meetings->fetchAll(PDO::FETCH_ASSOC);

        $calls = $pdo->prepare(
            "SELECT id, name, parent_type, parent_id, account_id,
                    TIME_FORMAT(CONVERT_TZ(date_start, '+00:00', '+03:00'), '%H:%i') AS tm
             FROM `call` WHERE deleted = 0 AND status = 'Planned'
               AND DATE(CONVERT_TZ(date_start, '+00:00', '+03:00')) = :today
             ORDER BY date_start ASC LIMIT 5"
        );
        $calls->execute([':today' => $today]);
        $callRows = $calls->fetchAll(PDO::FETCH_ASSOC);

        if ($taskRows === [] && $meetingRows === [] && $callRows === []) {
            $lines[] = '• На сегодня открытых задач, звонков и встреч нет';
        } else {
            foreach ($meetingRows as $m) {
                $url = $site . '/#Meeting/view/' . $m['id'];
                $lines[] = sprintf('• 🤝 %s — <a href="%s">%s</a>%s', $m['tm'] ?: 'Сегодня', htmlspecialchars($url, ENT_QUOTES), Format::escape((string) $m['name']), $this->resolveWho('Meeting', $m));
            }
            foreach ($callRows as $cl) {
                $url = $site . '/#Call/view/' . $cl['id'];
                $lines[] = sprintf('• 📞 %s — <a href="%s">%s</a>%s', $cl['tm'] ?: 'Сегодня', htmlspecialchars($url, ENT_QUOTES), Format::escape((string) $cl['name']), $this->resolveWho('Call', $cl));
            }
            foreach ($taskRows as $t) {
                $dueDay = (string) ($t['date_end_date'] ?: $t['due_day'] ?: '');
                $overdueTag = ($dueDay !== '' && $dueDay < $today) ? ' (просрочена с ' . Format::date($dueDay) . ')' : '';
                $url = $site . '/#Task/view/' . $t['id'];
                $lines[] = sprintf('• ✅ <a href="%s">%s</a>%s%s', htmlspecialchars($url, ENT_QUOTES), Format::escape(mb_substr((string) $t['name'], 0, 70)), $overdueTag, $this->resolveWho('Task', $t));
            }
        }

        $this->appendGithubStats($lines);

        return implode("\n", $lines);
    }

    private function resolveWho(string $entityType, array $row): string
    {
        $pdo = $this->entityManager->getPDO();
        $id = (string) ($row['id'] ?? '');
        $accountId = (string) ($row['account_id'] ?? '');
        $contactId = (string) ($row['contact_id'] ?? '');
        $parentType = (string) ($row['parent_type'] ?? '');
        $parentId = (string) ($row['parent_id'] ?? '');

        if ($parentId !== '') {
            if ($parentType === 'Account' && $accountId === '') {
                $accountId = $parentId;
            } elseif ($parentType === 'Contact' && $contactId === '') {
                $contactId = $parentId;
            } elseif ($parentType === 'Opportunity') {
                $s = $pdo->prepare("SELECT account_id, contact_id FROM opportunity WHERE id = ? AND deleted = 0");
                $s->execute([$parentId]);
                if ($o = $s->fetch(PDO::FETCH_ASSOC)) {
                    $accountId = $accountId ?: (string) ($o['account_id'] ?? '');
                    $contactId = $contactId ?: (string) ($o['contact_id'] ?? '');
                    if ($contactId === '') {
                        $cs = $pdo->prepare("SELECT contact_id FROM contact_opportunity WHERE opportunity_id = ? AND deleted = 0 LIMIT 1");
                        $cs->execute([$parentId]);
                        $contactId = (string) ($cs->fetchColumn() ?: '');
                    }
                }
            } elseif ($parentType === 'Case') {
                $s = $pdo->prepare("SELECT account_id, contact_id FROM `case` WHERE id = ? AND deleted = 0");
                $s->execute([$parentId]);
                if ($c = $s->fetch(PDO::FETCH_ASSOC)) {
                    $accountId = $accountId ?: (string) ($c['account_id'] ?? '');
                    $contactId = $contactId ?: (string) ($c['contact_id'] ?? '');
                    if ($contactId === '') {
                        $cs = $pdo->prepare("SELECT contact_id FROM case_contact WHERE case_id = ? AND deleted = 0 LIMIT 1");
                        $cs->execute([$parentId]);
                        $contactId = (string) ($cs->fetchColumn() ?: '');
                    }
                }
            } elseif ($parentType === 'Lead') {
                $s = $pdo->prepare("SELECT first_name, last_name, account_name FROM lead WHERE id = ? AND deleted = 0");
                $s->execute([$parentId]);
                if ($l = $s->fetch(PDO::FETCH_ASSOC)) {
                    $ln = trim(($l['last_name'] ?? '') . ' ' . ($l['first_name'] ?? ''));
                    $an = trim((string) ($l['account_name'] ?? ''));
                    $p = [];
                    $p[] = '🏢 ' . ($an !== '' ? Format::escape($an) : '—');
                    $p[] = '👤 ' . ($ln !== '' ? Format::escape($ln) : '—');
                    return "\n   ↳ " . implode(' · ', $p);
                }
            }
        }

        if ($contactId === '' && in_array($entityType, ['Meeting', 'Call'], true)) {
            $tbl = $entityType === 'Meeting' ? 'contact_meeting' : 'call_contact';
            $col = $entityType === 'Meeting' ? 'meeting_id' : 'call_id';
            $cs = $pdo->prepare("SELECT contact_id FROM {$tbl} WHERE {$col} = ? AND deleted = 0 LIMIT 1");
            $cs->execute([$id]);
            $contactId = (string) ($cs->fetchColumn() ?: '');
        }

        $contactName = '';
        $contactPhone = '';
        if ($contactId !== '') {
            $cs = $pdo->prepare(
                "SELECT c.first_name, c.last_name, c.middle_name, c.account_id, pn.name AS phone
                 FROM contact c
                 LEFT JOIN entity_phone_number epn ON epn.entity_id = c.id AND epn.entity_type = 'Contact' AND epn.deleted = 0 AND epn.`primary` = 1
                 LEFT JOIN phone_number pn ON pn.id = epn.phone_number_id AND pn.deleted = 0
                 WHERE c.id = ? AND c.deleted = 0 LIMIT 1"
            );
            $cs->execute([$contactId]);
            if ($cr = $cs->fetch(PDO::FETCH_ASSOC)) {
                $contactName = trim(($cr['last_name'] ?? '') . ' ' . ($cr['first_name'] ?? '') . ' ' . ($cr['middle_name'] ?? ''));
                $contactPhone = trim((string) ($cr['phone'] ?? ''));
                $accountId = $accountId ?: (string) ($cr['account_id'] ?? '');
            }
        }

        $accountName = '';
        if ($accountId !== '') {
            $as = $pdo->prepare("SELECT name FROM account WHERE id = ? AND deleted = 0 LIMIT 1");
            $as->execute([$accountId]);
            $accountName = trim((string) ($as->fetchColumn() ?: ''));
        }

        $parts = [];
        $parts[] = '🏢 ' . ($accountName !== '' ? Format::escape($accountName) : '—');
        $parts[] = '👤 ' . ($contactName !== '' ? (Format::escape($contactName) . ($contactPhone !== '' ? ' (' . Format::escape($contactPhone) . ')' : '')) : '—');

        return "\n   ↳ " . implode(' · ', $parts);
    }

    private function appendGithubStats(array &$lines): void
    {
        $repo = 'airuslan99-beep/espocrm-russian-b2b-pack';
        $stateFile = 'data/github-stars-state.json';

        try {
            $ch = curl_init('https://api.github.com/repos/' . $repo);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/vnd.github+json',
                    'User-Agent: RDAI-EspoCRM-Digest',
                ],
            ]);
            $raw = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($raw === false || $httpCode !== 200) {
                return;
            }

            $data = json_decode((string) $raw, true);
            if (!is_array($data)) {
                return;
            }

            $stars = (int) ($data['stargazers_count'] ?? 0);
            $forks = (int) ($data['forks_count'] ?? 0);

            $prev = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
            $prevStars = (int) ($prev['stars'] ?? $stars);
            $prevForks = (int) ($prev['forks'] ?? $forks);

            @file_put_contents($stateFile, json_encode([
                'stars' => $stars,
                'forks' => $forks,
                'updatedAt' => date('c'),
            ]));

            $diffStars = $stars - $prevStars;
            $diffForks = $forks - $prevForks;

            $starDelta = $diffStars > 0 ? ' (<b>+' . $diffStars . ' за сутки!</b>)' : '';
            $forkDelta = $diffForks > 0 ? ' (<b>+' . $diffForks . '</b>)' : '';

            $lines[] = '';
            $lines[] = '⭐ <b>GitHub (<a href="https://github.com/' . $repo . '">espocrm-russian-b2b-pack</a>)</b>';
            $lines[] = sprintf(
                '• Звёзд: <b>%d</b>%s · Форков: <b>%d</b>%s',
                $stars,
                $starDelta,
                $forks,
                $forkDelta
            );
        } catch (\Throwable) {
        }
    }
}
