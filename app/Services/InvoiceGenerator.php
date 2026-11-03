<?php

namespace App\Services;

use App\Models\UsageEvent;
use App\Models\SubscriptionSegment;
use App\Models\Subscription;
use App\Models\InvoiceLine;
use App\Models\Invoice;
use App\Models\DailyUsage;
use Illuminate\Database\Query\Expression;

use Carbon\CarbonImmutable;
use RuntimeException;

class InvoiceGenerator
{
    private $math;

    public function __construct(BillingMath $math)
    {
        $this->math = $math;
    }

    public function generate(int $subscriptionId, string $month): int
    {
        [$periodStart, $periodEnd] = BillingPeriod::forMonth($month);

        if ($periodEnd->greaterThan(CarbonImmutable::now('UTC'))) {
            throw new RuntimeException('The billing period has not ended.');
        }

        return Subscription::query()->getConnection()->transaction(function () use ($subscriptionId, $periodStart, $periodEnd) {
            // Lock before ordinary reads so finalized invoices include every committed event.
            Subscription::query()->where('id', $subscriptionId)->lockForUpdate()->first();

            $subscription = Subscription::query()->from('subscriptions as subscription')
                ->join('customers as customer', 'customer.id', '=', 'subscription.customer_id')
                ->join('merchants as merchant', 'merchant.id', '=', 'customer.merchant_id')
                ->where('subscription.id', $subscriptionId)
                ->select('subscription.*', 'merchant.currency')
                ->first();

            if (! $subscription) {
                throw new RuntimeException('Subscription not found.');
            }

            $start = $periodStart->format('Y-m-d H:i:s.u');
            $end = $periodEnd->format('Y-m-d H:i:s.u');
            $existing = Invoice::query()
                ->where('subscription_id', $subscriptionId)
                ->where('period_start', $start)
                ->where('period_end', $end)
                ->first();

            if ($existing) {
                return (int) $existing->id;
            }

            if ($subscription->started_at >= $end || ($subscription->ended_at && $subscription->ended_at <= $start)) {
                throw new RuntimeException('Subscription does not overlap the billing period.');
            }

            $segments = SubscriptionSegment::query()
                ->where('subscription_id', $subscriptionId)
                ->where('starts_at', '<', $end)
                ->where(function ($query) use ($start) {
                    $query->whereNull('ends_at')->orWhere('ends_at', '>', $start);
                })
                ->orderBy('starts_at')
                ->get();

            if ($segments->isEmpty()) {
                throw new RuntimeException('No plan segment overlaps the billing period.');
            }

            $pending = UsageEvent::query()
                ->whereIn('subscription_segment_id', $segments->pluck('id'))
                ->where('occurred_at', '>=', $start)
                ->where('occurred_at', '<', $end)
                ->whereNull('processed_at')
                ->exists();

            if ($pending) {
                throw new RuntimeException('Unaggregated usage remains in this billing period.');
            }

            $usage = DailyUsage::query()
                ->whereIn('subscription_segment_id', $segments->pluck('id'))
                ->where('usage_date', '>=', $periodStart->toDateString())
                ->where('usage_date', '<', $periodEnd->toDateString())
                ->groupBy('subscription_segment_id')
                ->select('subscription_segment_id', new Expression('SUM(units) as total_units'))
                ->pluck('total_units', 'subscription_segment_id');

            $invoiceId = Invoice::query()->insertGetId([
                'subscription_id' => $subscriptionId,
                'period_start' => $start,
                'period_end' => $end,
                'currency' => $subscription->currency,
                'status' => 'draft',
                'subtotal_minor' => 0,
                'total_minor' => 0,
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

            $total = 0;
            $subscriptionStart = CarbonImmutable::parse($subscription->started_at, 'UTC');
            $subscriptionEnd = $subscription->ended_at ? CarbonImmutable::parse($subscription->ended_at, 'UTC') : null;

            foreach ($segments as $segment) {
                if ($segment->currency !== $subscription->currency || $segment->billing_cycle !== 'monthly') {
                    throw new RuntimeException('Plan currency or billing cycle is incompatible with this invoice.');
                }

                $amounts = $this->math->segmentAmounts(
                    $segment,
                    (int) ($usage[$segment->id] ?? 0),
                    $periodStart,
                    $periodEnd,
                    $subscriptionStart,
                    $subscriptionEnd
                );

                if (! $amounts) {
                    continue;
                }

                $this->line($invoiceId, $segment->id, 'base', 'Prorated base charge', $amounts['fraction'], (string) $segment->base_price_minor, $amounts['base_minor']);
                $total += $amounts['base_minor'];

                if ($amounts['overage_units'] > 0) {
                    $this->line($invoiceId, $segment->id, 'overage', 'Usage above prorated allowance', (string) $amounts['overage_units'], (string) $segment->overage_rate_minor, $amounts['overage_minor']);
                    $total += $amounts['overage_minor'];
                }
            }

            Invoice::query()->where('id', $invoiceId)->update([
                'status' => 'finalized',
                'subtotal_minor' => $total,
                'total_minor' => $total,
                'generated_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

            return $invoiceId;
        }, 3);
    }

    private function line(int $invoiceId, int $segmentId, string $type, string $description, string $quantity, string $rate, int $amount): void
    {
        InvoiceLine::query()->insert([
            'invoice_id' => $invoiceId,
            'subscription_segment_id' => $segmentId,
            'type' => $type,
            'description' => $description,
            'quantity' => $quantity,
            'unit_rate_minor' => $rate,
            'amount_minor' => $amount,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
