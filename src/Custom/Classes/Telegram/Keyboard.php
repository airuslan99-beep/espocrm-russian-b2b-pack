<?php

declare(strict_types=1);

namespace Espo\Custom\Classes\Telegram;

use DateTimeImmutable;
use DateTimeZone;

final class Keyboard
{
    public const TYPES = ['Task' => 'T', 'Call' => 'C', 'Meeting' => 'M'];

    public static function data(string $action, string $entityType, string $id, ?string $arg = null): string
    {
        $parts = [$action, self::TYPES[$entityType] ?? '?', $id];

        if ($arg !== null) {
            $parts[] = $arg;
        }

        return implode('|', $parts);
    }

    public static function parse(string $data): ?array
    {
        $parts = explode('|', $data);

        if (count($parts) < 3 || count($parts) > 4) {
            return null;
        }

        $type = array_search($parts[1], self::TYPES, true);

        if ($type === false || !preg_match('/^[A-Za-z0-9]{1,24}$/', $parts[2])) {
            return null;
        }

        return [
            'action' => $parts[0],
            'type' => $type,
            'id' => $parts[2],
            'arg' => $parts[3] ?? null,
        ];
    }

    public static function main(string $type, string $id, ?string $url): array
    {
        $b = static fn(string $text, string $action): array => self::button($text, self::data($action, $type, $id));

        $rows = match ($type) {
            'Call' => [
                [$b('✔️ Состоялся', 'done'), $b('✖️ Не дозвонился', 'nh')],
                [$b('⏰ Перенести', 'pm'), $b('🔕 Через час', 'sn')],
                [$b('💬 Комментарий', 'cm')],
            ],
            'Meeting' => [
                [$b('✔️ Состоялась', 'done'), $b('✖️ Отменить', 'nh')],
                [$b('⏰ Перенести', 'pm'), $b('🔕 Через час', 'sn')],
                [$b('💬 Комментарий', 'cm')],
            ],
            default => [
                [$b('✅ Выполнено', 'done'), $b('⏰ Перенести', 'pm')],
                [$b('🔕 Через час', 'sn'), $b('💬 Комментарий', 'cm')],
            ],
        };

        if ($url !== null && preg_match('#^https?://[^/\s]+\.[^/\s]+#', $url)) {
            $rows[] = [['text' => '🔗 Открыть в CRM', 'url' => $url]];
        }

        return ['inline_keyboard' => $rows];
    }

    public static function postpone(string $type, string $id, string $action = 'p'): array
    {
        $b = static fn(string $text, string $arg): array => self::button($text, self::data($action, $type, $id, $arg));

        $rows = [
            [$b('+1 час', '1h'), $b('Завтра 10:30', 'tm')],
            [$b('+3 дня', '3d'), $b('+неделя', '1w')],
        ];

        if ($action === 'p') {
            $rows[] = [self::button('← Назад', self::data('back', $type, $id))];
        }

        return ['inline_keyboard' => $rows];
    }

    public static function next(string $taskId): array
    {
        return ['inline_keyboard' => [[
            self::button('➕ Следующая задача через 7 дней', self::data('nx', 'Task', $taskId)),
        ]]];
    }

    public static function target(string $arg, DateTimeImmutable $now, string $timeZone): ?DateTimeImmutable
    {
        $local = $now->setTimezone(new DateTimeZone($timeZone));

        $result = match ($arg) {
            '1h' => $local->modify('+1 hour'),
            'tm' => $local->modify('+1 day')->setTime(10, 30, 0),
            '3d' => $local->modify('+3 days')->setTime(10, 30, 0),
            '1w' => $local->modify('+7 days')->setTime(10, 30, 0),
            default => null,
        };

        if ($result === null) {
            return null;
        }

        $result = $result->setTime((int) $result->format('H'), (int) $result->format('i'), 0);

        return $result->setTimezone(new DateTimeZone('UTC'));
    }

    private static function button(string $text, string $data): array
    {
        return ['text' => $text, 'callback_data' => $data];
    }
}
