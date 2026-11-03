<?php

namespace App\Console\Commands;

use App\Services\SubscriptionManager;
use Illuminate\Console\Command;
use Throwable;

class StartSubscription extends Command
{
    protected $signature = 'subscription:start {customer_id} {plan_id} {started_at? : ISO 8601 date-time with timezone}';

    protected $description = 'Start a customer subscription using a cached plan price snapshot';

    public function handle(SubscriptionManager $manager)
    {
        try {
            $id = $manager->start(
                (int) $this->argument('customer_id'),
                (int) $this->argument('plan_id'),
                $this->argument('started_at') ?: now('UTC')->toIso8601String()
            );
            $this->info('Subscription created: '.$id);

            return 0;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return 1;
        }
    }
}
