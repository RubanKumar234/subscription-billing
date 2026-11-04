<?php

namespace Tests\Feature;

use App\Models\SubscriptionSegment;
use App\Models\Plan;
use App\Models\MerchantApiKey;
use App\Models\Merchant;
use App\Models\DailyUsage;
use App\Models\Customer;
use App\Services\SubscriptionManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchantDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_returns_top_usage_projected_overage_and_monthly_drop_for_its_merchant()
    {
        Carbon::setTestNow('2026-11-15 00:00:00 UTC');
        CarbonImmutable::setTestNow('2026-11-15 00:00:00 UTC');
        $merchantId = Merchant::query()->insertGetId([
            'name' => 'Dashboard Merchant', 'currency' => 'INR', 'status' => 'active',
        ]);
        $planId = Plan::query()->insertGetId([
            'merchant_id' => $merchantId,
            'name' => 'Metered', 'currency' => 'INR', 'base_price_minor' => 0,
            'included_units' => 0, 'overage_rate_minor' => '2.000000',
            'billing_cycle' => 'monthly', 'is_active' => true,
        ]);
        $customerId = Customer::query()->insertGetId([
            'merchant_id' => $merchantId, 'name' => 'Falling Usage',
        ]);
        $subscriptionId = app(SubscriptionManager::class)->start($customerId, $planId, '2026-10-01T00:00:00Z');
        $segmentId = SubscriptionSegment::query()->where('subscription_id', $subscriptionId)->value('id');
        DailyUsage::query()->insert([
            ['subscription_segment_id' => $segmentId, 'usage_date' => '2026-10-01', 'units' => 400],
            ['subscription_segment_id' => $segmentId, 'usage_date' => '2026-11-01', 'units' => 140],
        ]);

        $otherMerchantId = Merchant::query()->insertGetId([
            'name' => 'Other Dashboard Merchant', 'currency' => 'INR', 'status' => 'active',
        ]);
        $otherPlanId = Plan::query()->insertGetId([
            'merchant_id' => $otherMerchantId,
            'name' => 'Other Metered', 'currency' => 'INR', 'base_price_minor' => 0,
            'included_units' => 0, 'overage_rate_minor' => '2.000000',
            'billing_cycle' => 'monthly', 'is_active' => true,
        ]);
        $otherCustomerId = Customer::query()->insertGetId([
            'merchant_id' => $otherMerchantId, 'name' => 'Other Customer',
        ]);
        $otherSubscriptionId = app(SubscriptionManager::class)->start($otherCustomerId, $otherPlanId, '2026-10-01T00:00:00Z');
        $otherSegmentId = SubscriptionSegment::query()->where('subscription_id', $otherSubscriptionId)->value('id');
        DailyUsage::query()->insert([
            'subscription_segment_id' => $otherSegmentId, 'usage_date' => '2026-11-01', 'units' => 1000,
        ]);

        $key = bin2hex(random_bytes(32));
        MerchantApiKey::query()->insert([
            'merchant_id' => $merchantId, 'name' => 'test',
            'key_hash' => hash('sha256', $key), 'is_active' => true,
        ]);

        $response = $this->withHeader('X-Merchant-Key', $key)
            ->getJson('/merchants/'.$merchantId.'/dashboard');

        $response->assertOk()
            ->assertJsonPath('currency', 'INR')
            ->assertJsonPath('top_customers.0.customer_id', $customerId)
            ->assertJsonPath('top_customers.0.usage_units', 140)
            ->assertJsonPath('projected_overage_revenue_minor', 600)
            ->assertJsonPath('churn_risk_customers.0.drop_percent', 65)
            ->assertJsonCount(1, 'top_customers');

        $this->withHeader('X-Merchant-Key', $key)
            ->getJson('/merchants/'.$otherMerchantId.'/dashboard')->assertForbidden();
    }
}
