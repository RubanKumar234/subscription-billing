<?php

namespace Tests\Feature;

use App\Models\UsageEvent;
use App\Models\SubscriptionSegment;
use App\Models\Subscription;
use App\Models\Plan;
use App\Models\MerchantApiKey;
use App\Models\Merchant;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\InvoiceGenerator;
use Tests\TestCase;

class UsageEndpointTest extends TestCase
{
    use RefreshDatabase;

    private $merchantId;
    private $customerId;
    private $segmentId;
    private $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchantId = Merchant::query()->insertGetId([
            'name' => 'Test Merchant',
            'status' => 'active',
            'currency' => 'INR',
        ]);
        $planId = Plan::query()->insertGetId([
            'merchant_id' => $this->merchantId,
            'name' => 'Standard',
            'currency' => 'INR',
            'base_price_minor' => 10000,
            'included_units' => 1000,
            'overage_rate_minor' => 1,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);
        $this->customerId = Customer::query()->insertGetId([
            'merchant_id' => $this->merchantId,
            'name' => 'Test Customer',
        ]);
        $subscriptionId = Subscription::query()->insertGetId([
            'customer_id' => $this->customerId,
            'started_at' => '2026-11-01 00:00:00.000000',
            'status' => 'active',
        ]);
        $this->segmentId = SubscriptionSegment::query()->insertGetId([
            'subscription_id' => $subscriptionId,
            'plan_id' => $planId,
            'starts_at' => '2026-11-01 00:00:00.000000',
            'currency' => 'INR',
            'base_price_minor' => 10000,
            'included_units' => 1000,
            'overage_rate_minor' => 1,
            'billing_cycle' => 'monthly',
        ]);

        $this->apiKey = bin2hex(random_bytes(32));
        MerchantApiKey::query()->insert([
            'merchant_id' => $this->merchantId,
            'name' => 'test',
            'key_hash' => hash('sha256', $this->apiKey),
            'is_active' => true,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customerId,
            'event_id' => 'evt-001',
            'occurred_at' => '2026-11-03T10:00:00Z',
            'units' => 5,
        ], $overrides);
    }

    private function sendUsage(array $payload)
    {
        return $this->withHeader('X-Merchant-Key', $this->apiKey)
            ->postJson('/usage', $payload);
    }

    public function test_it_records_usage_once_for_retries()
    {
        $this->sendUsage($this->payload())->assertCreated()->assertJsonPath('duplicate', false);
        $this->sendUsage($this->payload())->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(1, UsageEvent::query()->count());
        $this->assertSame($this->segmentId, (int) UsageEvent::query()->value('subscription_segment_id'));
    }

    public function test_it_rejects_conflicting_reuse_of_an_event_id()
    {
        $this->sendUsage($this->payload())->assertCreated();
        $this->sendUsage($this->payload(['units' => 6]))->assertStatus(409);

        $this->assertSame(1, UsageEvent::query()->count());
    }

    public function test_it_rejects_missing_or_invalid_merchant_key()
    {
        $this->postJson('/usage', $this->payload())->assertUnauthorized();
        $this->withHeader('X-Merchant-Key', str_repeat('0', 64))
            ->postJson('/usage', $this->payload())->assertUnauthorized();
    }

    public function test_it_rejects_usage_outside_the_merchants_customer_and_segment()
    {
        $otherMerchantId = Merchant::query()->insertGetId([
            'name' => 'Other Merchant',
            'status' => 'active',
            'currency' => 'INR',
        ]);
        $otherCustomerId = Customer::query()->insertGetId([
            'merchant_id' => $otherMerchantId,
            'name' => 'Other Customer',
        ]);

        $this->sendUsage($this->payload(['customer_id' => $otherCustomerId]))->assertStatus(422);
        $this->sendUsage($this->payload(['occurred_at' => '2026-10-31T23:59:59Z']))->assertStatus(422);
        $this->assertSame(0, UsageEvent::query()->count());
    }

    public function test_plan_change_boundary_assigns_usage_to_the_new_segment()
    {
        SubscriptionSegment::query()->where('id', $this->segmentId)
            ->update(['ends_at' => '2026-11-03 12:00:00.000000']);

        $newSegmentId = SubscriptionSegment::query()->insertGetId([
            'subscription_id' => SubscriptionSegment::query()->where('id', $this->segmentId)->value('subscription_id'),
            'plan_id' => SubscriptionSegment::query()->where('id', $this->segmentId)->value('plan_id'),
            'starts_at' => '2026-11-03 12:00:00.000000',
            'currency' => 'INR',
            'base_price_minor' => 20000,
            'included_units' => 2000,
            'overage_rate_minor' => 2,
            'billing_cycle' => 'monthly',
        ]);

        $this->sendUsage($this->payload(['occurred_at' => '2026-11-03T11:59:59Z']))->assertCreated();
        $this->sendUsage($this->payload(['event_id' => 'evt-002', 'occurred_at' => '2026-11-03T12:00:00Z']))->assertCreated();

        $this->assertSame($this->segmentId, (int) UsageEvent::query()->where('event_id', 'evt-001')->value('subscription_segment_id'));
        $this->assertSame($newSegmentId, (int) UsageEvent::query()->where('event_id', 'evt-002')->value('subscription_segment_id'));
    }

    public function test_it_rate_limits_usage_requests()
    {
        for ($attempt = 1; $attempt <= 60; $attempt++) {
            $this->sendUsage($this->payload(['event_id' => 'rate-'.$attempt]))->assertCreated();
        }

        $this->sendUsage($this->payload(['event_id' => 'rate-61']))->assertStatus(429);
    }

    public function test_finalized_period_rejects_new_events_but_accepts_identical_retries()
    {
        $subscriptionId = SubscriptionSegment::query()->where('id', $this->segmentId)->value('subscription_id');
        $original = $this->payload([
            'event_id' => 'closed-original',
            'occurred_at' => '2025-10-10T10:00:00Z',
        ]);
        Subscription::query()->where('id', $subscriptionId)->update(['started_at' => '2025-10-01 00:00:00.000000']);
        SubscriptionSegment::query()->where('id', $this->segmentId)->update(['starts_at' => '2025-10-01 00:00:00.000000']);

        $this->sendUsage($original)->assertCreated();
        app(\App\Services\UsageAggregator::class)->processBatch('2025-10');
        app(InvoiceGenerator::class)->generate($subscriptionId, '2025-10');

        $this->sendUsage($original)->assertOk()->assertJsonPath('duplicate', true);
        $this->sendUsage($this->payload([
            'event_id' => 'closed-new', 'occurred_at' => '2025-10-11T10:00:00Z',
        ]))->assertStatus(409);
    }
}
