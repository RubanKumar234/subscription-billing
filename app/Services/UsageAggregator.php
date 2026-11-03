<?php

namespace App\Services;

use App\Models\UsageEvent;
use App\Models\Subscription;
use App\Models\DailyUsage;
use Carbon\Carbon;

class UsageAggregator
{
    public const BATCH_SIZE = 500;

    public function processBatch(?string $month = null): int
    {
        return Subscription::query()->getConnection()->transaction(function () use ($month) {
            $query = UsageEvent::query()
                ->whereNull('processed_at')
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->lockForUpdate();

            if ($month !== null) {
                [$start, $end] = BillingPeriod::forMonth($month);
                $query->where('occurred_at', '>=', $start->format('Y-m-d H:i:s.u'))
                    ->where('occurred_at', '<', $end->format('Y-m-d H:i:s.u'));
            }

            $events = $query->get(['id', 'subscription_segment_id', 'occurred_at', 'units']);
            if ($events->isEmpty()) {
                return 0;
            }

            $totals = [];
            foreach ($events as $event) {
                $date = substr($event->occurred_at, 0, 10);
                $key = $event->subscription_segment_id.'|'.$date;
                if (! isset($totals[$key])) {
                    $totals[$key] = [
                        'segment_id' => (int) $event->subscription_segment_id,
                        'date' => $date,
                        'units' => 0,
                    ];
                }
                $totals[$key]['units'] += (int) $event->units;
            }

            $now = Carbon::now('UTC')->format('Y-m-d H:i:s');
            foreach ($totals as $total) {
                DailyUsage::query()->getConnection()->statement(
                    'INSERT INTO daily_usage (subscription_segment_id, usage_date, units, created_at, updated_at) '
                    .'VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE units = units + VALUES(units), updated_at = VALUES(updated_at)',
                    [$total['segment_id'], $total['date'], $total['units'], $now, $now]
                );
            }

            UsageEvent::query()
                ->whereIn('id', $events->pluck('id'))
                ->update(['processed_at' => $now]);

            return $events->count();
        }, 3);
    }
}
