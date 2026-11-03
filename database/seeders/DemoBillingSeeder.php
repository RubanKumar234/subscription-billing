<?php

namespace Database\Seeders;

use App\Models\UsageEvent;
use App\Models\SubscriptionSegment;
use App\Models\Subscription;
use App\Models\Merchant;
use App\Models\Customer;
use App\Models\Plan;
use App\Services\SubscriptionManager;
use App\Services\UsageRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

class DemoBillingSeeder extends Seeder
{
    public function run()
    {
        $previousStart = CarbonImmutable::now('UTC')->startOfMonth()->subMonth();
        $currentStart = $previousStart->addMonth();
        $changeAt = $previousStart->addDays(15);

        $merchantId = Merchant::query()->where('name', 'Mallow Billing Demo')->value('id');
        if (! $merchantId) {
            $merchantId = Merchant::query()->insertGetId([
                'name' => 'Mallow Billing Demo', 'status' => 'active', 'currency' => 'INR',
            ]);
        }

        $basic = Plan::firstOrCreate(
            ['merchant_id' => $merchantId, 'name' => 'Basic'],
            ['currency' => 'INR', 'base_price_minor' => 3100, 'included_units' => 310,
                'overage_rate_minor' => '2.000000', 'billing_cycle' => 'monthly', 'is_active' => true]
        );
        $growth = Plan::firstOrCreate(
            ['merchant_id' => $merchantId, 'name' => 'Growth'],
            ['currency' => 'INR', 'base_price_minor' => 6200, 'included_units' => 620,
                'overage_rate_minor' => '3.000000', 'billing_cycle' => 'monthly', 'is_active' => true]
        );

        $customerOne = $this->customer($merchantId, 'Acme Customer');
        $customerTwo = $this->customer($merchantId, 'Beta Customer');
        $manager = app(SubscriptionManager::class);
        $subscriptionOne = $this->subscription($manager, $customerOne, $basic->id, $previousStart);
        $subscriptionTwo = $this->subscription($manager, $customerTwo, $basic->id, $previousStart);

        if (! SubscriptionSegment::query()->where('subscription_id', $subscriptionOne)
            ->where('starts_at', $changeAt->format('Y-m-d H:i:s.u'))->exists()) {
            $manager->changePlan($subscriptionOne, $growth->id, $changeAt->toIso8601String());
        }

        $this->event($merchantId, $customerOne, 'demo-'.$previousStart->format('Y-m').'-acme-early', $previousStart->addHours(10), 600);
        $this->event($merchantId, $customerOne, 'demo-'.$previousStart->format('Y-m').'-acme-late', $changeAt->addDays(2), 500);
        $this->event($merchantId, $customerTwo, 'demo-'.$previousStart->format('Y-m').'-beta', $previousStart->addHours(11), 100);
        $this->event($merchantId, $customerOne, 'demo-'.$currentStart->format('Y-m').'-acme', CarbonImmutable::now('UTC'), 120);
        $this->event($merchantId, $customerTwo, 'demo-'.$currentStart->format('Y-m').'-beta', CarbonImmutable::now('UTC'), 300);

        $this->command->info('Demo merchant ID: '.$merchantId.'. Issue a key with: php artisan merchant:issue-key '.$merchantId);
        $this->command->info('Plans: Basic='.$basic->id.', Growth='.$growth->id.'. Customers: Acme='.$customerOne.', Beta='.$customerTwo.'.');
        $this->command->info('Subscriptions: Acme='.$subscriptionOne.', Beta='.$subscriptionTwo.'.');
        $this->command->info('Run billing:close '.$previousStart->format('Y-m').' to generate last month\'s invoices.');
    }

    private function customer(int $merchantId, string $name): int
    {
        $id = Customer::query()->where('merchant_id', $merchantId)->where('name', $name)->value('id');

        return $id ?: Customer::query()->insertGetId([
            'merchant_id' => $merchantId, 'name' => $name,
        ]);
    }

    private function subscription(SubscriptionManager $manager, int $customerId, int $planId, CarbonImmutable $start): int
    {
        $id = Subscription::query()->where('customer_id', $customerId)
            ->where('started_at', $start->format('Y-m-d H:i:s.u'))->value('id');

        return $id ?: $manager->start($customerId, $planId, $start->toIso8601String());
    }

    private function event(int $merchantId, int $customerId, string $eventId, CarbonImmutable $at, int $units): void
    {
        if (UsageEvent::query()->where('merchant_id', $merchantId)->where('event_id', $eventId)->exists()) {
            return;
        }

        $result = app(UsageRecorder::class)->record($merchantId, [
            'customer_id' => $customerId,
            'event_id' => $eventId,
            'occurred_at' => $at->toIso8601String(),
            'units' => $units,
        ]);

        if ($result['status'] !== 201) {
            throw new RuntimeException('Could not seed usage event '.$eventId.': '.$result['body']['message']);
        }
    }
}
