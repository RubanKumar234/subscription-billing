<?php

namespace App\Services;

use App\Models\UsageEvent;
use App\Models\SubscriptionSegment;
use App\Models\Subscription;
use App\Models\Merchant;
use App\Models\Invoice;
use App\Models\Customer;
use Carbon\Carbon;
use RuntimeException;

class SubscriptionManager
{
    private $pricing;

    public function __construct(PlanPricingRepository $pricing)
    {
        $this->pricing = $pricing;
    }

    public function start(int $customerId, int $planId, string $startsAt): int
    {
        $startsAt = Carbon::parse($startsAt)->utc()->format('Y-m-d H:i:s.u');

        return Subscription::query()->getConnection()->transaction(function () use ($customerId, $planId, $startsAt) {
            $customer = Customer::query()->where('id', $customerId)->lockForUpdate()->first();
            if (! $customer) {
                throw new RuntimeException('Customer not found.');
            }

            if (Subscription::query()->where('customer_id', $customerId)
                ->where(function ($query) use ($startsAt) {
                    $query->whereNull('ended_at')->orWhere('ended_at', '>', $startsAt);
                })->exists()) {
                throw new RuntimeException('Customer already has an overlapping subscription.');
            }

            $plan = $this->pricing->get((int) $customer->merchant_id, $planId);
            $this->assertPlanMatchesMerchant($customer->merchant_id, $plan);

            $subscriptionId = Subscription::query()->insertGetId([
                'customer_id' => $customerId,
                'started_at' => $startsAt,
                'status' => 'active',
                'created_at' => now('UTC'),
                'updated_at' => now('UTC'),
            ]);

            $this->insertSegment($subscriptionId, $plan, $startsAt);

            return $subscriptionId;
        }, 3);
    }

    public function changePlan(int $subscriptionId, int $planId, string $at): int
    {
        $at = Carbon::parse($at)->utc()->format('Y-m-d H:i:s.u');

        return Subscription::query()->getConnection()->transaction(function () use ($subscriptionId, $planId, $at) {
            $subscription = Subscription::query()->where('id', $subscriptionId)->lockForUpdate()->first();
            if (! $subscription || $subscription->status !== 'active' || $subscription->started_at >= $at ||
                ($subscription->ended_at && $subscription->ended_at <= $at)) {
                throw new RuntimeException('No active subscription exists at the requested time.');
            }

            $customer = Customer::query()->where('id', $subscription->customer_id)->first();
            $plan = $this->pricing->get((int) $customer->merchant_id, $planId);
            $this->assertPlanMatchesMerchant($customer->merchant_id, $plan);

            $monthStart = Carbon::parse($at, 'UTC')->startOfMonth()->format('Y-m-d H:i:s.u');
            $monthEnd = Carbon::parse($at, 'UTC')->startOfMonth()->addMonth()->format('Y-m-d H:i:s.u');
            if (Invoice::query()->where('subscription_id', $subscriptionId)
                ->where('period_start', $monthStart)->where('period_end', $monthEnd)
                ->where('status', 'finalized')->exists()) {
                throw new RuntimeException('Cannot change a plan in a finalized billing period.');
            }

            $current = SubscriptionSegment::query()
                ->where('subscription_id', $subscriptionId)
                ->whereNull('ends_at')
                ->lockForUpdate()
                ->first();
            if (! $current || $current->starts_at >= $at) {
                throw new RuntimeException('Plan change must occur after the current segment starts.');
            }

            if (UsageEvent::query()->where('subscription_segment_id', $current->id)
                ->where('occurred_at', '>=', $at)->exists()) {
                throw new RuntimeException('Usage already exists after the requested plan change time.');
            }

            SubscriptionSegment::query()->where('id', $current->id)->update([
                'ends_at' => $at,
                'updated_at' => now('UTC'),
            ]);

            return $this->insertSegment($subscriptionId, $plan, $at);
        }, 3);
    }

    private function assertPlanMatchesMerchant(int $merchantId, array $plan): void
    {
        $currency = Merchant::query()->where('id', $merchantId)->value('currency');
        if ($plan['currency'] !== $currency || $plan['billing_cycle'] !== 'monthly') {
            throw new RuntimeException('Plan must use the merchant currency and a monthly cycle.');
        }
    }

    private function insertSegment(int $subscriptionId, array $plan, string $startsAt): int
    {
        return SubscriptionSegment::query()->insertGetId([
            'subscription_id' => $subscriptionId,
            'plan_id' => $plan['plan_id'],
            'starts_at' => $startsAt,
            'currency' => $plan['currency'],
            'base_price_minor' => $plan['base_price_minor'],
            'included_units' => $plan['included_units'],
            'overage_rate_minor' => $plan['overage_rate_minor'],
            'billing_cycle' => $plan['billing_cycle'],
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
