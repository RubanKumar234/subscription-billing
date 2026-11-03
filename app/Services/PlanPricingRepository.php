<?php

namespace App\Services;

use App\Models\Plan;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class PlanPricingRepository
{
    public static function cacheKey(int $merchantId, int $planId): string
    {
        return 'plan-pricing:'.$merchantId.':'.$planId;
    }

    public function get(int $merchantId, int $planId): array
    {
        $pricing = Cache::remember(self::cacheKey($merchantId, $planId), 600, function () use ($merchantId, $planId) {
            $plan = Plan::where('merchant_id', $merchantId)
                ->where('id', $planId)
                ->where('is_active', true)
                ->first();

            if (! $plan) {
                return null;
            }

            return [
                'plan_id' => (int) $plan->id,
                'currency' => $plan->currency,
                'base_price_minor' => (int) $plan->base_price_minor,
                'included_units' => (int) $plan->included_units,
                'overage_rate_minor' => (string) $plan->overage_rate_minor,
                'billing_cycle' => $plan->billing_cycle,
            ];
        });

        if ($pricing === null) {
            throw new RuntimeException('Active plan not found for merchant.');
        }

        return $pricing;
    }
}
