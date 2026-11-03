<?php

namespace App\Services;

use Carbon\CarbonImmutable;

class BillingMath
{
    public function segmentAmounts(object $segment, int $usageUnits, CarbonImmutable $periodStart, CarbonImmutable $periodEnd, CarbonImmutable $subscriptionStart, ?CarbonImmutable $subscriptionEnd): ?array
    {
        $start = max($periodStart, $subscriptionStart, CarbonImmutable::parse($segment->starts_at, 'UTC'));
        $end = min(
            $periodEnd,
            $subscriptionEnd ?: $periodEnd,
            $segment->ends_at ? CarbonImmutable::parse($segment->ends_at, 'UTC') : $periodEnd
        );

        if ($start >= $end) {
            return null;
        }

        $seconds = $end->timestamp - $start->timestamp;
        $periodSeconds = $periodEnd->timestamp - $periodStart->timestamp;
        if ($seconds <= 0 || $periodSeconds <= 0) {
            return null;
        }

        $baseNumerator = bcmul((string) $segment->base_price_minor, (string) $seconds, 0);
        $allowanceNumerator = bcmul((string) $segment->included_units, (string) $seconds, 0);
        $baseMinor = (int) bcdiv(bcadd($baseNumerator, (string) intdiv($periodSeconds, 2), 0), (string) $periodSeconds, 0);
        $includedUnits = (int) bcdiv($allowanceNumerator, (string) $periodSeconds, 0);
        $overageUnits = max(0, $usageUnits - $includedUnits);
        $overageMinor = $this->overageMinor($overageUnits, (string) $segment->overage_rate_minor);

        return [
            'starts_at' => $start,
            'ends_at' => $end,
            'fraction' => bcdiv((string) $seconds, (string) $periodSeconds, 6),
            'base_minor' => $baseMinor,
            'included_units' => $includedUnits,
            'usage_units' => $usageUnits,
            'overage_units' => $overageUnits,
            'overage_minor' => $overageMinor,
        ];
    }

    public function overageMinor(int $units, string $rateMinor): int
    {
        $amount = bcmul((string) $units, $rateMinor, 6);

        return (int) bcdiv(bcadd($amount, '0.5', 6), '1', 0);
    }
}
