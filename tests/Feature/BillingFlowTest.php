<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Services\InvoiceGenerator;
use App\Services\PlanPricingRepository;
use App\Services\SubscriptionManager;
use App\Services\UsageAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BillingFlowTest extends TestCase
{
    use RefreshDatabase;

    private $merchantId;
    private $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->merchantId = DB::table('merchants')->insertGetId([
            'name' => 'Billing Merchant', 'status' => 'active', 'currency' => 'INR',
        ]);
        $this->customerId = DB::table('customers')->insertGetId([
            'merchant_id' => $this->merchantId, 'name' => 'Billing Customer',
        ]);
    }

    private function plan(string $name, int $base, int $included, string $rate): Plan
    {
        return Plan::create([
            'merchant_id' => $this->merchantId,
            'name' => $name,
            'currency' => 'INR',
            'base_price_minor' => $base,
            'included_units' => $included,
            'overage_rate_minor' => $rate,
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);
    }

    private function daily(int $segmentId, string $date, int $units): void
    {
        DB::table('daily_usage')->insert([
            'subscription_segment_id' => $segmentId,
            'usage_date' => $date,
            'units' => $units,
        ]);
    }

    public function test_mid_month_start_prorates_base_and_allowance_then_charges_overage()
    {
        $plan = $this->plan('Starter', 3100, 310, '2.000000');
        $subscriptionId = app(SubscriptionManager::class)->start($this->customerId, $plan->id, '2025-10-16T00:00:00Z');
        $segmentId = DB::table('subscription_segments')->where('subscription_id', $subscriptionId)->value('id');
        $this->daily($segmentId, '2025-10-20', 200);

        $invoiceId = app(InvoiceGenerator::class)->generate($subscriptionId, '2025-10');
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        $lines = DB::table('invoice_lines')->where('invoice_id', $invoiceId)->pluck('amount_minor', 'type');

        $this->assertSame(1680, (int) $invoice->total_minor);
        $this->assertSame(1600, (int) $lines['base']);
        $this->assertSame(80, (int) $lines['overage']);
        $this->assertSame($invoiceId, app(InvoiceGenerator::class)->generate($subscriptionId, '2025-10'));
        $this->assertSame(1, DB::table('invoices')->count());
    }

    public function test_upgrade_bills_each_segments_usage_at_its_own_rate()
    {
        $old = $this->plan('Old', 3100, 310, '2.000000');
        $new = $this->plan('New', 6200, 620, '3.000000');
        $manager = app(SubscriptionManager::class);
        $subscriptionId = $manager->start($this->customerId, $old->id, '2025-10-01T00:00:00Z');
        $oldSegmentId = DB::table('subscription_segments')->where('subscription_id', $subscriptionId)->value('id');
        $newSegmentId = $manager->changePlan($subscriptionId, $new->id, '2025-10-16T00:00:00Z');
        $this->daily($oldSegmentId, '2025-10-10', 200);
        $this->daily($newSegmentId, '2025-10-20', 400);

        $invoiceId = app(InvoiceGenerator::class)->generate($subscriptionId, '2025-10');
        $lines = DB::table('invoice_lines')->where('invoice_id', $invoiceId)
            ->orderBy('subscription_segment_id')->orderBy('id')->get();

        $this->assertSame(5040, (int) DB::table('invoices')->where('id', $invoiceId)->value('total_minor'));
        $this->assertSame([1500, 100, 3200, 240], $lines->pluck('amount_minor')->map(function ($value) {
            return (int) $value;
        })->all());
    }

    public function test_downgrade_and_exact_allowance_do_not_charge_extra_usage()
    {
        $high = $this->plan('High', 6200, 620, '3.000000');
        $low = $this->plan('Low', 3100, 310, '2.000000');
        $manager = app(SubscriptionManager::class);
        $subscriptionId = $manager->start($this->customerId, $high->id, '2025-10-01T00:00:00Z');
        $oldSegmentId = DB::table('subscription_segments')->where('subscription_id', $subscriptionId)->value('id');
        $newSegmentId = $manager->changePlan($subscriptionId, $low->id, '2025-10-16T00:00:00Z');
        $this->daily($oldSegmentId, '2025-10-10', 300);
        $this->daily($newSegmentId, '2025-10-20', 160);

        $invoiceId = app(InvoiceGenerator::class)->generate($subscriptionId, '2025-10');

        $this->assertSame(4600, (int) DB::table('invoices')->where('id', $invoiceId)->value('total_minor'));
        $this->assertSame(0, DB::table('invoice_lines')->where('invoice_id', $invoiceId)->where('type', 'overage')->count());
        $this->assertSame($newSegmentId, (int) DB::table('subscription_segments')->whereNull('ends_at')->value('id'));
    }

    public function test_plan_price_cache_invalidates_but_old_segment_keeps_its_snapshot()
    {
        $plan = $this->plan('Changeable', 3100, 310, '2.000000');
        $pricing = app(PlanPricingRepository::class);
        $this->assertSame(3100, $pricing->get($this->merchantId, $plan->id)['base_price_minor']);

        $subscriptionId = app(SubscriptionManager::class)->start($this->customerId, $plan->id, '2025-10-01T00:00:00Z');
        $plan->update(['base_price_minor' => 6200]);

        $this->assertSame(6200, $pricing->get($this->merchantId, $plan->id)['base_price_minor']);
        $this->assertSame(3100, (int) DB::table('subscription_segments')->where('subscription_id', $subscriptionId)->value('base_price_minor'));
    }

    public function test_aggregation_processes_bounded_batches_and_retry_does_not_double_count()
    {
        $plan = $this->plan('Usage', 0, 0, '1.000000');
        $subscriptionId = app(SubscriptionManager::class)->start($this->customerId, $plan->id, '2025-10-01T00:00:00Z');
        $segmentId = DB::table('subscription_segments')->where('subscription_id', $subscriptionId)->value('id');
        $events = [];
        for ($id = 1; $id <= 501; $id++) {
            $events[] = [
                'merchant_id' => $this->merchantId,
                'customer_id' => $this->customerId,
                'subscription_segment_id' => $segmentId,
                'event_id' => 'batch-'.$id,
                'occurred_at' => '2025-10-10 10:00:00.000000',
                'units' => 1,
            ];
        }
        foreach (array_chunk($events, 200) as $chunk) {
            DB::table('usage_events')->insert($chunk);
        }

        $aggregator = app(UsageAggregator::class);
        $this->assertSame(500, $aggregator->processBatch('2025-10'));
        $this->assertSame(1, $aggregator->processBatch('2025-10'));
        $this->assertSame(0, $aggregator->processBatch('2025-10'));
        $this->assertSame(501, (int) DB::table('daily_usage')->where('subscription_segment_id', $segmentId)->value('units'));
        $this->assertSame(501, DB::table('usage_events')->whereNotNull('processed_at')->count());
    }

    public function test_invoice_rejects_unaggregated_usage()
    {
        $plan = $this->plan('Pending', 100, 0, '1.000000');
        $subscriptionId = app(SubscriptionManager::class)->start($this->customerId, $plan->id, '2025-10-01T00:00:00Z');
        $segmentId = DB::table('subscription_segments')->where('subscription_id', $subscriptionId)->value('id');
        DB::table('usage_events')->insert([
            'merchant_id' => $this->merchantId, 'customer_id' => $this->customerId,
            'subscription_segment_id' => $segmentId, 'event_id' => 'pending',
            'occurred_at' => '2025-10-10 10:00:00.000000', 'units' => 1,
        ]);

        $this->expectExceptionMessage('Unaggregated usage remains');
        app(InvoiceGenerator::class)->generate($subscriptionId, '2025-10');
    }

    public function test_month_close_aggregates_then_generates_one_invoice_even_when_retried()
    {
        $plan = $this->plan('Close', 3100, 310, '2.000000');
        $subscriptionId = app(SubscriptionManager::class)->start($this->customerId, $plan->id, '2025-10-01T00:00:00Z');
        $segmentId = DB::table('subscription_segments')->where('subscription_id', $subscriptionId)->value('id');
        DB::table('usage_events')->insert([
            'merchant_id' => $this->merchantId, 'customer_id' => $this->customerId,
            'subscription_segment_id' => $segmentId, 'event_id' => 'month-close-event',
            'occurred_at' => '2025-10-10 10:00:00.000000', 'units' => 350,
        ]);

        $this->artisan('billing:close', ['month' => '2025-10'])->assertExitCode(0);
        $this->artisan('billing:close', ['month' => '2025-10'])->assertExitCode(0);

        $this->assertSame(350, (int) DB::table('daily_usage')->where('subscription_segment_id', $segmentId)->value('units'));
        $this->assertSame(1, DB::table('invoices')->where('subscription_id', $subscriptionId)->count());
        $this->assertSame(3180, (int) DB::table('invoices')->where('subscription_id', $subscriptionId)->value('total_minor'));
    }

    public function test_one_unit_over_allowance_uses_fractional_rate_and_rounds_the_line()
    {
        $plan = $this->plan('Fractional', 1000, 100, '0.500000');
        $subscriptionId = app(SubscriptionManager::class)->start($this->customerId, $plan->id, '2025-10-01T00:00:00Z');
        $segmentId = DB::table('subscription_segments')->where('subscription_id', $subscriptionId)->value('id');
        $this->daily($segmentId, '2025-10-10', 101);

        $invoiceId = app(InvoiceGenerator::class)->generate($subscriptionId, '2025-10');

        $this->assertSame(1001, (int) DB::table('invoices')->where('id', $invoiceId)->value('total_minor'));
        $this->assertSame(1, (int) DB::table('invoice_lines')->where('invoice_id', $invoiceId)
            ->where('type', 'overage')->value('amount_minor'));
    }
}
