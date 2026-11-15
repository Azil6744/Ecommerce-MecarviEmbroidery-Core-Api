<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Membership plan-change date & proration math (authoritative, server side).
 *
 * Mirrors Ecommerce-MecarviTechnologies-Web/lib/membership-proration.ts — keep in sync.
 *
 * - Work on calendar dates only (no time of day) and integer cents.
 * - Current period is [start, end). The change day belongs to the NEW plan, so
 *   days used = days from start up to (not including) today.
 * - Unused credit = oldPrice × daysRemaining / daysInPeriod.
 * - immediate:  new period starts today; due today = newPrice − credit (negative → refund).
 * - next_cycle: nothing due today; new price charged on the current period end.
 */
class MembershipProrationService
{
    public static function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::create((int) $value->format('Y'), (int) $value->format('m'), (int) $value->format('d'), 0, 0, 0, 'UTC');
        }
        $raw = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $raw, $m)) {
            return CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, 'UTC');
        }
        try {
            $d = CarbonImmutable::parse($raw);
            return CarbonImmutable::create($d->year, $d->month, $d->day, 0, 0, 0, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }

    public static function today(): CarbonImmutable
    {
        $now = now(config('app.timezone', 'UTC'));
        return CarbonImmutable::create($now->year, $now->month, $now->day, 0, 0, 0, 'UTC');
    }

    public static function cycleMonths(?string $cycle, ?int $intervalCount = 1): int
    {
        $c = strtolower((string) ($cycle ?: 'monthly'));
        $count = max(1, (int) ($intervalCount ?: 1));
        $months = 1;
        if (str_contains($c, 'semi') || str_contains($c, 'six') || str_contains($c, 'half')) {
            $months = 6;
        } elseif (str_contains($c, 'year') || str_contains($c, 'annual')) {
            $months = 12;
        } elseif (str_contains($c, 'quarter')) {
            $months = 3;
        }
        return $months * $count;
    }

    public static function addMonths(CarbonImmutable $date, int $months): CarbonImmutable
    {
        return $date->addMonthsNoOverflow($months);
    }

    public static function daysBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400);
    }

    /**
     * @return array{start: CarbonImmutable, end: CarbonImmutable, total_days: int, rolled_forward: bool}|null
     */
    public static function resolveCurrentPeriod(mixed $periodStart, mixed $periodEnd, mixed $nextBillingDate, ?string $cycle, ?int $intervalCount = 1, ?CarbonImmutable $today = null): ?array
    {
        $today ??= self::today();
        $months = self::cycleMonths($cycle, $intervalCount);
        $storedStart = self::parseDate($periodStart);
        $storedEnd = self::parseDate($periodEnd);
        $storedNext = self::parseDate($nextBillingDate);

        $end0 = $storedNext ?? $storedEnd;
        if ($storedStart && $end0 && $storedStart->lt($end0) && $storedStart->lte($today) && $today->lt($end0)) {
            return ['start' => $storedStart, 'end' => $end0, 'total_days' => self::daysBetween($storedStart, $end0), 'rolled_forward' => false];
        }

        $anchor = $end0 ?? ($storedStart ? self::addMonths($storedStart, $months) : null);
        if (! $anchor) {
            return null;
        }

        $at = fn (int $n) => self::addMonths($anchor, $n * $months);
        $k = 0;
        while ($at($k)->lte($today)) {
            $k++;
        }
        while ($at($k - 1)->gt($today)) {
            $k--;
        }
        $start = $at($k - 1);
        $end = $at($k);

        return ['start' => $start, 'end' => $end, 'total_days' => self::daysBetween($start, $end), 'rolled_forward' => $k > 0];
    }

    public static function compute(string $timing, float $currentPrice, float $newPrice, ?string $newCycle, ?int $newIntervalCount, ?array $period, ?CarbonImmutable $today = null): array
    {
        $today ??= self::today();
        $currentCents = (int) round($currentPrice * 100);
        $newCents = (int) round($newPrice * 100);
        $direction = $newCents > $currentCents ? 'upgrade' : ($newCents < $currentCents ? 'downgrade' : 'lateral');
        $newMonths = self::cycleMonths($newCycle, $newIntervalCount);

        $totalDays = $period['total_days'] ?? 0;
        $daysUsed = $period ? min(max(self::daysBetween($period['start'], $today), 0), $totalDays) : 0;
        $daysRemaining = $period ? $totalDays - $daysUsed : 0;

        $base = [
            'timing' => $timing,
            'direction' => $direction,
            'today' => $today->toDateString(),
            'period_start' => $period ? $period['start']->toDateString() : null,
            'period_end' => $period ? $period['end']->toDateString() : null,
            'period_days' => $totalDays,
            'period_rolled_forward' => (bool) ($period['rolled_forward'] ?? false),
            'days_used' => $daysUsed,
            'days_remaining' => $daysRemaining,
            'current_price' => $currentCents / 100,
            'new_price' => $newCents / 100,
            'next_renewal_amount' => $newCents / 100,
        ];

        if ($timing === 'next_cycle') {
            $effective = $period ? $period['end'] : $today;
            return $base + [
                'unused_credit' => 0.0,
                'new_plan_charge' => 0.0,
                'net' => 0.0,
                'amount_due_today' => 0.0,
                'refund_amount' => 0.0,
                'effective_date' => $effective->toDateString(),
                'new_period_start' => $effective->toDateString(),
                'new_period_end' => self::addMonths($effective, $newMonths)->toDateString(),
                'next_billing_date' => $effective->toDateString(),
            ];
        }

        $creditCents = $totalDays > 0 ? (int) round($currentCents * $daysRemaining / $totalDays) : 0;
        $netCents = $newCents - $creditCents;
        $newEnd = self::addMonths($today, $newMonths);

        return $base + [
            'unused_credit' => $creditCents / 100,
            'new_plan_charge' => $newCents / 100,
            'net' => $netCents / 100,
            'amount_due_today' => max($netCents, 0) / 100,
            'refund_amount' => max(-$netCents, 0) / 100,
            'effective_date' => $today->toDateString(),
            'new_period_start' => $today->toDateString(),
            'new_period_end' => $newEnd->toDateString(),
            'next_billing_date' => $newEnd->toDateString(),
        ];
    }
}
