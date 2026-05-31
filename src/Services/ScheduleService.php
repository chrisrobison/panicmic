<?php

declare(strict_types=1);

namespace PanicMic\Services;

use DateTimeImmutable;
use DateTimeInterface;
use PDO;

/**
 * Recurring show templates and the logic that turns them into concrete
 * `events` rows (the calendar). Recurrence supports weekly, biweekly and
 * monthly (nth-weekday) cadences plus a date window.
 *
 * `occurrencesFor()` is a pure function — given a schedule and a date
 * window it returns the datetimes it fires on — which makes the recurrence
 * math fully unit-testable without a database.
 */
final class ScheduleService
{
    public const RECURRENCE_TYPES = ['weekly', 'biweekly', 'monthly'];

    /** @return list<array<string,mixed>> */
    public static function all(PDO $db, bool $includeInactive = false): array
    {
        $where = $includeInactive ? '' : 'WHERE s.is_active = 1';
        return $db->query(
            "SELECT s.*, v.name AS venue_name
             FROM show_schedules s
             JOIN venues v ON v.id = s.venue_id
             {$where}
             ORDER BY s.is_active DESC, v.name ASC, s.start_time ASC"
        )->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public static function find(PDO $db, int $id): ?array
    {
        $stmt = $db->prepare('SELECT * FROM show_schedules WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function create(PDO $db, array $data): array
    {
        $fields = self::sanitize($data, true);
        $columns = array_keys($fields);
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));
        $db->prepare(
            'INSERT INTO show_schedules (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')'
        )->execute(array_values($fields));
        $id = (int)$db->lastInsertId();
        self::materializeSchedule($db, $id);
        return self::find($db, $id) ?? ['id' => $id];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    public static function update(PDO $db, int $id, array $data): ?array
    {
        if (!self::find($db, $id)) {
            return null;
        }
        $fields = self::sanitize($data, false);
        if (array_key_exists('is_active', $data)) {
            $fields['is_active'] = !empty($data['is_active']) ? 1 : 0;
        }
        if ($fields) {
            $assignments = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", array_keys($fields)));
            $params = array_values($fields);
            $params[] = $id;
            $db->prepare("UPDATE show_schedules SET {$assignments} WHERE id = ?")->execute($params);
        }
        self::materializeSchedule($db, $id);
        return self::find($db, $id);
    }

    public static function deactivate(PDO $db, int $id): bool
    {
        if (!self::find($db, $id)) {
            return false;
        }
        $db->prepare('UPDATE show_schedules SET is_active = 0 WHERE id = ?')->execute([$id]);
        return true;
    }

    /**
     * Compute the datetimes a schedule fires on within [$from, $to]
     * (inclusive of the day boundaries). Pure: no DB access.
     *
     * @param array<string,mixed> $schedule
     * @return list<string> 'Y-m-d H:i:s' datetimes, ascending
     */
    public static function occurrencesFor(array $schedule, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $weekday = (int)$schedule['weekday']; // 0=Sun .. 6=Sat
        $time = self::normalizeTime((string)($schedule['start_time'] ?? '20:00:00'));
        $type = (string)($schedule['recurrence_type'] ?? 'weekly');

        $startsOn = self::toDate((string)$schedule['starts_on']);
        $endsOn = !empty($schedule['ends_on']) ? self::toDate((string)$schedule['ends_on']) : null;

        // Clamp the search window to the schedule's own validity window.
        $windowStart = self::maxDate(self::toDate($from->format('Y-m-d')), $startsOn);
        $windowEnd = self::toDate($to->format('Y-m-d'));
        if ($endsOn !== null) {
            $windowEnd = self::minDate($windowEnd, $endsOn);
        }
        if ($windowStart > $windowEnd) {
            return [];
        }

        $dates = $type === 'monthly'
            ? self::monthlyDates($schedule, $weekday, $windowStart, $windowEnd)
            : self::weeklyDates($schedule, $type, $weekday, $windowStart, $windowEnd);

        $out = [];
        foreach ($dates as $date) {
            $out[] = $date->format('Y-m-d') . ' ' . $time;
        }
        sort($out);
        return $out;
    }

    /** Top up materialized events across a rolling horizon for all active schedules. */
    public static function materialize(PDO $db, int $horizonDays = 120): int
    {
        $count = 0;
        foreach (self::all($db, false) as $schedule) {
            $count += self::materializeSchedule($db, (int)$schedule['id'], $horizonDays);
        }
        return $count;
    }

    /**
     * Materialize a single schedule. Uses INSERT IGNORE keyed on
     * (schedule_id, scheduled_for) so it never duplicates and never
     * resurrects an occurrence the KJ already edited or canceled.
     */
    public static function materializeSchedule(PDO $db, int $scheduleId, int $horizonDays = 120): int
    {
        $schedule = self::find($db, $scheduleId);
        if (!$schedule || (int)$schedule['is_active'] !== 1) {
            return 0;
        }
        $from = new DateTimeImmutable('today');
        $to = $from->modify("+{$horizonDays} days");
        $occurrences = self::occurrencesFor($schedule, $from, $to);
        if (!$occurrences) {
            return 0;
        }
        $stmt = $db->prepare(
            'INSERT IGNORE INTO events (venue_id, schedule_id, name, scheduled_for, status)
             VALUES (?, ?, ?, ?, ?)'
        );
        $count = 0;
        foreach ($occurrences as $datetime) {
            $stmt->execute([
                (int)$schedule['venue_id'],
                $scheduleId,
                (string)$schedule['name'],
                $datetime,
                'scheduled',
            ]);
            $count += $stmt->rowCount();
        }
        return $count;
    }

    /* --------------------------- internals --------------------------- */

    /** @return list<DateTimeImmutable> */
    private static function weeklyDates(array $schedule, string $type, int $weekday, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $cursor = self::weekdayOnOrAfter($start, $weekday);
        $anchor = null;
        if ($type === 'biweekly') {
            $anchorRaw = !empty($schedule['anchor_date'])
                ? self::toDate((string)$schedule['anchor_date'])
                : self::toDate((string)$schedule['starts_on']);
            $anchor = self::weekdayOnOrAfter($anchorRaw, $weekday);
        }
        $dates = [];
        while ($cursor <= $end) {
            if ($type !== 'biweekly' || self::isOnPhase($anchor, $cursor)) {
                $dates[] = $cursor;
            }
            $cursor = $cursor->modify('+7 days');
        }
        return $dates;
    }

    /** @return list<DateTimeImmutable> */
    private static function monthlyDates(array $schedule, int $weekday, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $week = (int)($schedule['week_of_month'] ?? 1);
        $dates = [];
        $month = $start->modify('first day of this month')->setTime(0, 0);
        while ($month <= $end) {
            $occurrence = self::nthWeekdayOfMonth($month, $weekday, $week);
            if ($occurrence !== null && $occurrence >= $start && $occurrence <= $end) {
                $dates[] = $occurrence;
            }
            $month = $month->modify('first day of next month');
        }
        return $dates;
    }

    private static function weekdayOnOrAfter(DateTimeImmutable $date, int $weekday): DateTimeImmutable
    {
        $date = $date->setTime(0, 0);
        $delta = ($weekday - (int)$date->format('w') + 7) % 7;
        return $date->modify("+{$delta} days");
    }

    /** Biweekly phase: candidate is "on" when an even number of weeks from the anchor. */
    private static function isOnPhase(?DateTimeImmutable $anchor, DateTimeImmutable $candidate): bool
    {
        if ($anchor === null) {
            return true;
        }
        $days = (int)round(($candidate->getTimestamp() - $anchor->getTimestamp()) / 86400);
        $weeks = intdiv($days, 7);
        return ((($weeks % 2) + 2) % 2) === 0;
    }

    private static function nthWeekdayOfMonth(DateTimeImmutable $month, int $weekday, int $n): ?DateTimeImmutable
    {
        if ($n === -1) {
            $last = $month->modify('last day of this month')->setTime(0, 0);
            $delta = ((int)$last->format('w') - $weekday + 7) % 7;
            return $last->modify("-{$delta} days");
        }
        $first = self::weekdayOnOrAfter($month, $weekday);
        $occurrence = $first->modify('+' . (($n - 1) * 7) . ' days');
        // Guard against e.g. a "5th Tuesday" that doesn't exist this month.
        if ((int)$occurrence->format('n') !== (int)$month->format('n')) {
            return null;
        }
        return $occurrence;
    }

    private static function toDate(string $value): DateTimeImmutable
    {
        return (new DateTimeImmutable($value))->setTime(0, 0);
    }

    private static function maxDate(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable
    {
        return $a >= $b ? $a : $b;
    }

    private static function minDate(DateTimeImmutable $a, DateTimeImmutable $b): DateTimeImmutable
    {
        return $a <= $b ? $a : $b;
    }

    private static function normalizeTime(string $time): string
    {
        $time = trim($time);
        if (preg_match('/^\d{1,2}:\d{2}$/', $time)) {
            return $time . ':00';
        }
        if (preg_match('/^\d{1,2}:\d{2}:\d{2}$/', $time)) {
            return $time;
        }
        return '20:00:00';
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function sanitize(array $data, bool $isCreate): array
    {
        $out = [];

        if (array_key_exists('venue_id', $data) || $isCreate) {
            $venueId = (int)($data['venue_id'] ?? 0);
            if ($venueId <= 0) {
                throw new \InvalidArgumentException('A venue is required');
            }
            $out['venue_id'] = $venueId;
        }
        if (array_key_exists('name', $data) || $isCreate) {
            $name = trim((string)($data['name'] ?? ''));
            if ($name === '') {
                throw new \InvalidArgumentException('A show name is required');
            }
            $out['name'] = $name;
        }
        if (array_key_exists('recurrence_type', $data) || $isCreate) {
            $type = (string)($data['recurrence_type'] ?? 'weekly');
            if (!in_array($type, self::RECURRENCE_TYPES, true)) {
                throw new \InvalidArgumentException('Invalid recurrence type');
            }
            $out['recurrence_type'] = $type;
        }
        if (array_key_exists('weekday', $data) || $isCreate) {
            $weekday = (int)($data['weekday'] ?? 0);
            if ($weekday < 0 || $weekday > 6) {
                throw new \InvalidArgumentException('Weekday must be 0 (Sunday) through 6 (Saturday)');
            }
            $out['weekday'] = $weekday;
        }
        if (array_key_exists('week_of_month', $data)) {
            $week = $data['week_of_month'];
            $out['week_of_month'] = ($week === '' || $week === null) ? null : (int)$week;
        }
        if (array_key_exists('start_time', $data) || $isCreate) {
            $out['start_time'] = self::normalizeTime((string)($data['start_time'] ?? '20:00'));
        }
        if (array_key_exists('duration_minutes', $data)) {
            $d = $data['duration_minutes'];
            $out['duration_minutes'] = ($d === '' || $d === null) ? null : (int)$d;
        }
        if (array_key_exists('anchor_date', $data)) {
            $out['anchor_date'] = $data['anchor_date'] ?: null;
        }
        if (array_key_exists('starts_on', $data) || $isCreate) {
            $startsOn = (string)($data['starts_on'] ?? '');
            $out['starts_on'] = $startsOn !== '' ? $startsOn : (new DateTimeImmutable('today'))->format('Y-m-d');
        }
        if (array_key_exists('ends_on', $data)) {
            $out['ends_on'] = $data['ends_on'] ?: null;
        }

        // Biweekly needs an anchor to define its phase; default it to the
        // start date so "every other week" is deterministic from day one.
        if (($out['recurrence_type'] ?? null) === 'biweekly'
            && empty($out['anchor_date'])
            && !empty($out['starts_on'])
        ) {
            $out['anchor_date'] = $out['starts_on'];
        }

        return $out;
    }
}
