<?php

declare(strict_types=1);

namespace Espo\Custom\Classes\Document;

use DateTimeImmutable;

/**
 * Russian production calendar, five-day week.
 * 2026: Government decree of 24.09.2025 No. 1466.
 * 2027: Government decree of 17.09.2026 No. 1187.
 * Years without a list fall back to weekends plus statutory holidays.
 */
final class WorkingDays
{
    private const HOLIDAYS = [
        2026 => [
            '2026-01-01', '2026-01-02', '2026-01-05', '2026-01-06', '2026-01-07',
            '2026-01-08', '2026-01-09', '2026-02-23', '2026-03-09', '2026-05-01',
            '2026-05-11', '2026-06-12', '2026-11-04', '2026-12-31',
        ],
        2027 => [
            '2027-01-01', '2027-01-04', '2027-01-05', '2027-01-06', '2027-01-07',
            '2027-01-08', '2027-02-22', '2027-02-23', '2027-03-08', '2027-05-03',
            '2027-05-10', '2027-06-14', '2027-11-04', '2027-11-05', '2027-12-31',
        ],
    ];

    private const WORKING_WEEKENDS = [
        2026 => [],
        2027 => [],
    ];

    private const STATUTORY = [
        '01-01', '01-02', '01-03', '01-04', '01-05', '01-06', '01-07', '01-08',
        '02-23', '03-08', '05-01', '05-09', '06-12', '11-04',
    ];

    public static function add(DateTimeImmutable $date, int $days): DateTimeImmutable
    {
        $current = $date->setTime(0, 0);
        $added = 0;

        while ($added < $days) {
            $current = $current->modify('+1 day');

            if (self::isWorkingDay($current)) {
                $added++;
            }
        }

        return $current;
    }

    public static function isWorkingDay(DateTimeImmutable $date): bool
    {
        $day = $date->format('Y-m-d');
        $year = (int) $date->format('Y');

        if (in_array($day, self::WORKING_WEEKENDS[$year] ?? [], true)) {
            return true;
        }

        if ((int) $date->format('N') >= 6) {
            return false;
        }

        if (isset(self::HOLIDAYS[$year])) {
            return !in_array($day, self::HOLIDAYS[$year], true);
        }

        return !in_array($date->format('m-d'), self::STATUTORY, true);
    }
}
