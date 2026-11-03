<?php

namespace App\Console\Commands;

use App\Services\SubscriptionManager;
use Illuminate\Console\Command;
use Throwable;

class ChangeSubscriptionPlan extends Command
{
    protected $signature = 'subscription:change-plan {subscription_id} {plan_id} {at? : ISO 8601 date-time with timezone}';

    protected $description = 'Create a new immutable pricing segment for a plan change';

    public function handle(SubscriptionManager $manager)
    {
        try {
            $id = $manager->changePlan(
                (int) $this->argument('subscription_id'),
                (int) $this->argument('plan_id'),
                $this->argument('at') ?: now('UTC')->toIso8601String()
            );
            $this->info('New plan segment created: '.$id);

            return 0;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return 1;
        }
    }
}
