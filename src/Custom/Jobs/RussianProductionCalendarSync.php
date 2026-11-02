<?php

namespace Espo\Custom\Jobs;

use DateTimeImmutable;
use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use SimpleXMLElement;

class RussianProductionCalendarSync implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Log $log
    ) {}

    public function run(): void
    {
        $calendar = $this->resolveCalendar();
        if (!$calendar) {
            $this->log->warning('RussianProductionCalendarSync: WorkingTimeCalendar not found.');
            return;
        }

        $calendarId = (string) $calendar->getId();
        $currentYear = (int) date('Y');
        $years = [$currentYear, $currentYear + 1];

        foreach ($years as $year) {
            $this->syncYear($year, $calendarId, $calendar);
        }
    }

    private function resolveCalendar(): ?\Espo\ORM\Entity
    {
        $defaultId = $this->config->get('workingTimeCalendarId');
        if ($defaultId) {
            $cal = $this->entityManager->getEntityById('WorkingTimeCalendar', (string) $defaultId);
            if ($cal) {
                return $cal;
            }
        }

        return $this->entityManager
            ->getRDBRepository('WorkingTimeCalendar')
            ->findOne();
    }

    private function syncYear(int $year, string $calendarId, \Espo\ORM\Entity $calendar): void
    {
        $url = "https://xmlcalendar.ru/data/ru/{$year}/calendar.xml";
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 15,
                'user_agent' => 'EspoCRM-RussianProductionCalendarSync/1.0',
            ],
        ]);

        $xmlString = @file_get_contents($url, false, $ctx);
        if (!$xmlString) {
            $this->log->notice("RussianProductionCalendarSync: Calendar XML for {$year} is not available yet.");
            return;
        }

        $xml = @simplexml_load_string($xmlString);
        if (!$xml instanceof SimpleXMLElement) {
            $this->log->warning("RussianProductionCalendarSync: Failed to parse XML for {$year}.");
            return;
        }

        $holidays = [];
        if (isset($xml->holidays->holiday)) {
            foreach ($xml->holidays->holiday as $h) {
                $id = (string) $h['id'];
                $title = trim((string) $h['title']);
                if ($id !== '') {
                    $holidays[$id] = $title;
                }
            }
        }

        if (!isset($xml->days->day)) {
            return;
        }

        $synced = 0;

        foreach ($xml->days->day as $dayNode) {
            $d = (string) $dayNode['d']; // MM.DD
            $t = (int) $dayNode['t'];    // 1=non-working, 2=shortened, 3=working weekend
            $h = (string) ($dayNode['h'] ?? '');
            $f = (string) ($dayNode['f'] ?? '');

            if (!preg_match('/^(\d{2})\.(\d{2})$/', $d, $m)) {
                continue;
            }

            $dateStr = sprintf('%04d-%02d-%02d', $year, (int) $m[1], (int) $m[2]);

            if ($t === 1) {
                $type = 'Non-working';
                $timeRanges = null;
                if ($h !== '' && isset($holidays[$h])) {
                    $name = $holidays[$h];
                } elseif ($f !== '') {
                    $fromParts = explode('.', $f);
                    $fromFormatted = count($fromParts) === 2 ? "{$fromParts[1]}.{$fromParts[0]}" : $f;
                    $name = "Перенесённый выходной (с {$fromFormatted})";
                } else {
                    $name = 'Праздничный / выходной день (РФ)';
                }
            } elseif ($t === 2) {
                $type = 'Working';
                $timeRanges = $this->buildShortenedRanges($calendar, $dateStr);
                $name = 'Предпраздничный сокращённый день';
            } elseif ($t === 3) {
                $type = 'Working';
                $timeRanges = $this->buildStandardWeekdayRanges($calendar);
                $name = 'Рабочий выходной (перенос РФ)';
            } else {
                continue;
            }

            $this->upsertRange($calendarId, $dateStr, $type, $name, $timeRanges);
            $synced++;
        }

        $this->log->info("RussianProductionCalendarSync: Synced {$synced} days for year {$year}.");
    }

    private function buildStandardWeekdayRanges(\Espo\ORM\Entity $calendar): array
    {
        $ranges = $calendar->get('weekday1TimeRanges') ?: $calendar->get('timeRanges');
        if (is_array($ranges) && count($ranges) > 0) {
            return $ranges;
        }
        return [['09:00', '18:00']];
    }

    private function buildShortenedRanges(\Espo\ORM\Entity $calendar, string $dateStr): array
    {
        $dt = new DateTimeImmutable($dateStr);
        $w = (int) $dt->format('w'); // 0 (Sun) .. 6 (Sat)
        $field = "weekday{$w}TimeRanges";

        $ranges = $calendar->get($field) ?: $calendar->get('timeRanges');
        if (!is_array($ranges) || empty($ranges)) {
            $ranges = [['09:00', '18:00']];
        }

        $lastIdx = count($ranges) - 1;
        $endStr = $ranges[$lastIdx][1] ?? '18:00';

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $endStr, $m)) {
            $hour = max(0, ((int) $m[1]) - 1);
            $ranges[$lastIdx][1] = sprintf('%02d:%s', $hour, $m[2]);
        }

        return $ranges;
    }

    private function upsertRange(
        string $calendarId,
        string $dateStr,
        string $type,
        string $name,
        ?array $timeRanges
    ): void {
        $existing = $this->entityManager
            ->getRDBRepository('WorkingTimeRange')
            ->where([
                'dateStart' => $dateStr,
                'dateEnd' => $dateStr,
                'description' => 'RF_PROD_CALENDAR',
            ])
            ->findOne();

        if (!$existing) {
            $existing = $this->entityManager->getNewEntity('WorkingTimeRange');
        }

        $existing->set([
            'name' => $name,
            'description' => 'RF_PROD_CALENDAR',
            'dateStart' => $dateStr,
            'dateEnd' => $dateStr,
            'type' => $type,
            'timeRanges' => $timeRanges,
            'calendarsIds' => [$calendarId],
        ]);

        $this->entityManager->saveEntity($existing);
    }
}
