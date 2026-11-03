<?php

namespace Tests\Feature;

use App\Services\SubscriptionManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $merchantId = DB::table('merchants')->insertGetId([
            'name' => 'Dashboard Merchant', 'currency' => 'INR', 'status' => 'active',
        ]);
        $planId = DB::table('plans')->insertGetId([
            'merchant_id' => $merchantId,
            'name' => 'Metered', 'currency' => 'INR', 'base_price_minor' => 0,
            'included_units' => 0, 'overage_rate_minor' => '2.000000',
            'billing_cycle' => 'monthly', 'is_active' => true,
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'merchant_id' => $merchantId, 'name' => 'Falling Usage',
        ]);
        $subscriptionId = app(SubscriptionManager::class)->start($customerId, $planId, '2026-10-01T00:00:00Z');
        $segmentId = DB::table('subscription_segments')->where('subscription_id', $subscriptionId)->value('id');
        DB::table('daily_usage')->insert([
            ['subscription_segment_id' => $segmentId, 'usage_date' => '2026-10-01', 'units' => 400],
            ['subscription_segment_id' => $segmentId, 'usage_date' => '2026-11-01', 'units' => 140],
        ]);

        $key = bin2hex(random_bytes(32));
        DB::table('merchant_api_keys')->insert([
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
            ->assertJsonPath('churn_risk_customers.0.drop_percent', 65);

        $this->withHeader('X-Merchant-Key', $key)
            ->getJson('/merchants/'.($merchantId + 1).'/dashboard')->assertForbidden();
    }
}
