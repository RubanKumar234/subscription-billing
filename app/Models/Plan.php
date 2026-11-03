<?php

namespace App\Models;

use App\Services\PlanPricingRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Plan extends Model
{
    protected $fillable = [
        'merchant_id', 'name', 'currency', 'base_price_minor',
        'included_units', 'overage_rate_minor', 'billing_cycle', 'is_active',
    ];

    protected $casts = [
        'base_price_minor' => 'integer',
        'included_units' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted()
    {
        static::saved(function (Plan $plan) {
            Cache::forget(PlanPricingRepository::cacheKey($plan->merchant_id, $plan->id));
            if ($plan->wasChanged('merchant_id')) {
                Cache::forget(PlanPricingRepository::cacheKey($plan->getOriginal('merchant_id'), $plan->id));
            }
        });

        static::deleted(function (Plan $plan) {
            Cache::forget(PlanPricingRepository::cacheKey($plan->merchant_id, $plan->id));
        });
    }
}
