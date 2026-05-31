<?php

declare(strict_types=1);

namespace PanicMic\Tests\Services;

use DateTimeImmutable;
use PanicMic\Services\ScheduleService;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the recurrence generator — no database. June 2026
 * Thursdays are the 4th, 11th, 18th and 25th; the first Friday is the 5th
 * and the last Friday is the 26th.
 */
final class ScheduleOccurrencesTest extends TestCase
{
    private const JUNE_START = '2026-06-01';
    private const JUNE_END = '2026-06-30';

    private function occur(array $overrides, string $from = self::JUNE_START, string $to = self::JUNE_END): array
    {
        $schedule = array_merge([
            'recurrence_type' => 'weekly',
            'weekday' => 4, // Thursday
            'start_time' => '20:00:00',
            'starts_on' => self::JUNE_START,
            'ends_on' => null,
            'anchor_date' => null,
            'week_of_month' => null,
        ], $overrides);

        return ScheduleService::occurrencesFor(
            $schedule,
            new DateTimeImmutable($from),
            new DateTimeImmutable($to),
        );
    }

    public function testWeeklyHitsEveryThursday(): void
    {
        self::assertSame([
            '2026-06-04 20:00:00',
            '2026-06-11 20:00:00',
            '2026-06-18 20:00:00',
            '2026-06-25 20:00:00',
        ], $this->occur([]));
    }

    public function testBiweeklySkipsAlternateWeeks(): void
    {
        // Anchor on the 4th: "on" weeks are the 4th and the 18th.
        self::assertSame([
            '2026-06-04 20:00:00',
            '2026-06-18 20:00:00',
        ], $this->occur(['recurrence_type' => 'biweekly', 'anchor_date' => '2026-06-04']));
    }

    public function testMonthlyFirstFriday(): void
    {
        self::assertSame(
            ['2026-06-05 20:00:00'],
            $this->occur(['recurrence_type' => 'monthly', 'weekday' => 5, 'week_of_month' => 1]),
        );
    }

    public function testMonthlyLastFriday(): void
    {
        self::assertSame(
            ['2026-06-26 20:00:00'],
            $this->occur(['recurrence_type' => 'monthly', 'weekday' => 5, 'week_of_month' => -1]),
        );
    }

    public function testStartsOnClampsTheWindow(): void
    {
        // A schedule that only starts on the 12th skips the 4th and 11th.
        self::assertSame([
            '2026-06-18 20:00:00',
            '2026-06-25 20:00:00',
        ], $this->occur(['starts_on' => '2026-06-12']));
    }

    public function testEndsOnClampsTheWindow(): void
    {
        self::assertSame([
            '2026-06-04 20:00:00',
            '2026-06-11 20:00:00',
        ], $this->occur(['ends_on' => '2026-06-15']));
    }

    public function testNormalizesShortTimeFormat(): void
    {
        $out = $this->occur(['start_time' => '21:30']);
        self::assertSame('2026-06-04 21:30:00', $out[0]);
    }

    public function testEmptyWhenWindowOutsideValidity(): void
    {
        // Schedule ends in June; a July window yields nothing.
        self::assertSame([], $this->occur(['ends_on' => '2026-06-30'], '2026-07-01', '2026-07-03'));
    }
}
