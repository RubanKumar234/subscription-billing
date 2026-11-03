<?php

namespace App\Services;

use App\Models\SubscriptionSegment;
use App\Models\Merchant;
use App\Models\DailyUsage;
use Illuminate\Database\Query\Expression;

use Carbon\CarbonImmutable;
use RuntimeException;

class MerchantDashboard
{
    private $math;

    public function __construct(BillingMath $math)
    {
        $this->math = $math;
    }

    public function forMerchant(int $merchantId): array
    {
        $merchant = Merchant::query()->where('id', $merchantId)->first();
        if (! $merchant) {
            throw new RuntimeException('Merchant not found.');
        }

        $now = CarbonImmutable::now('UTC');
        $start = $now->startOfMonth();
        $end = $start->addMonth();
        $todayEnd = $now->startOfDay()->addDay()->toDateString();
        $previousStart = $start->subMonth();
        $previousEnd = $previousStart
            ->addDays(min($now->day, $previousStart->daysInMonth))
            ->toDateString();

        $top = DailyUsage::query()->from('daily_usage as daily')
            ->join('subscription_segments as segment', 'segment.id', '=', 'daily.subscription_segment_id')
            ->join('subscriptions as subscription', 'subscription.id', '=', 'segment.subscription_id')
            ->join('customers as customer', 'customer.id', '=', 'subscription.customer_id')
            ->where('customer.merchant_id', $merchantId)
            ->where('daily.usage_date', '>=', $start->toDateString())
            ->where('daily.usage_date', '<', $todayEnd)
            ->groupBy('customer.id', 'customer.name')
            ->orderByDesc('usage_units')
            ->limit(5)
            ->select('customer.id', 'customer.name', new Expression('SUM(daily.units) as usage_units'))
            ->get()
            ->map(function ($row) {
                return ['customer_id' => (int) $row->id, 'name' => $row->name, 'usage_units' => (int) $row->usage_units];
            })
            ->all();

        $churn = DailyUsage::query()->from('daily_usage as daily')
            ->join('subscription_segments as segment', 'segment.id', '=', 'daily.subscription_segment_id')
            ->join('subscriptions as subscription', 'subscription.id', '=', 'segment.subscription_id')
            ->join('customers as customer', 'customer.id', '=', 'subscription.customer_id')
            ->where('customer.merchant_id', $merchantId)
            ->where('daily.usage_date', '>=', $previousStart->toDateString())
            ->where('daily.usage_date', '<', $todayEnd)
            ->groupBy('customer.id', 'customer.name')
            ->select('customer.id', 'customer.name')
            ->selectRaw('SUM(CASE WHEN daily.usage_date >= ? AND daily.usage_date < ? THEN daily.units ELSE 0 END) as previous_units', [
                $previousStart->toDateString(), $previousEnd,
            ])
            ->selectRaw('SUM(CASE WHEN daily.usage_date >= ? AND daily.usage_date < ? THEN daily.units ELSE 0 END) as current_units', [
                $start->toDateString(), $todayEnd,
            ])
            ->havingRaw('previous_units > 0 AND current_units * 2 < previous_units')
            ->get()
            ->map(function ($row) {
                $previous = (int) $row->previous_units;
                $current = (int) $row->current_units;

                return [
                    'customer_id' => (int) $row->id,
                    'name' => $row->name,
                    'previous_units' => $previous,
                    'current_units' => $current,
                    'drop_percent' => round(($previous - $current) * 100 / $previous, 1),
                ];
            })
            ->all();

        return [
            'merchant_id' => $merchantId,
            'currency' => $merchant->currency,
            'cycle_start' => $start->toIso8601String(),
            'cycle_end' => $end->toIso8601String(),
            'top_customers' => $top,
            'projected_overage_revenue_minor' => $this->projectedOverage($merchantId, $start, $end, $now),
            'churn_risk_customers' => $churn,
        ];
    }

    private function projectedOverage(int $merchantId, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $now): int
    {
        $segments = SubscriptionSegment::query()->from('subscription_segments as segment')
            ->join('subscriptions as subscription', 'subscription.id', '=', 'segment.subscription_id')
            ->join('customers as customer', 'customer.id', '=', 'subscription.customer_id')
            ->where('customer.merchant_id', $merchantId)
            ->where('subscription.started_at', '<', $end->format('Y-m-d H:i:s.u'))
            ->where(function ($query) use ($start) {
                $query->whereNull('subscription.ended_at')
                    ->orWhere('subscription.ended_at', '>', $start->format('Y-m-d H:i:s.u'));
            })
            ->where('segment.starts_at', '<', $end->format('Y-m-d H:i:s.u'))
            ->where(function ($query) use ($start) {
                $query->whereNull('segment.ends_at')
                    ->orWhere('segment.ends_at', '>', $start->format('Y-m-d H:i:s.u'));
            })
            ->select('segment.*', 'subscription.started_at as subscription_started_at', 'subscription.ended_at as subscription_ended_at')
            ->get();

        $total = 0;
        foreach ($segments->chunk(500) as $chunk) {
            $usage = DailyUsage::query()
                ->whereIn('subscription_segment_id', $chunk->pluck('id'))
                ->where('usage_date', '>=', $start->toDateString())
                ->where('usage_date', '<=', $now->toDateString())
                ->groupBy('subscription_segment_id')
                ->select('subscription_segment_id', new Expression('SUM(units) as total_units'))
                ->pluck('total_units', 'subscription_segment_id');

            foreach ($chunk as $segment) {
                $subscriptionStart = CarbonImmutable::parse($segment->subscription_started_at, 'UTC');
                $subscriptionEnd = $segment->subscription_ended_at
                    ? CarbonImmutable::parse($segment->subscription_ended_at, 'UTC') : null;
                $segmentStart = max($start, $subscriptionStart, CarbonImmutable::parse($segment->starts_at, 'UTC'));
                $segmentEnd = min($end, $subscriptionEnd ?: $end, $segment->ends_at
                    ? CarbonImmutable::parse($segment->ends_at, 'UTC') : $end);

                if ($segmentStart >= $segmentEnd) {
                    continue;
                }

                $actualUnits = (int) ($usage[$segment->id] ?? 0);
                $elapsedEnd = min($now, $segmentEnd);
                $elapsed = max(0, $elapsedEnd->timestamp - $segmentStart->timestamp);
                $scheduled = $segmentEnd->timestamp - $segmentStart->timestamp;
                $projectedUnits = $elapsed > 0 && $elapsed < $scheduled
                    ? (int) round($actualUnits * $scheduled / $elapsed)
                    : $actualUnits;

                $amounts = $this->math->segmentAmounts(
                    $segment, $projectedUnits, $start, $end, $subscriptionStart, $subscriptionEnd
                );
                $total += $amounts['overage_minor'] ?? 0;
            }
        }

        return $total;
    }
}
