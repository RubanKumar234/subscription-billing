<?php

namespace App\Console\Commands;

use App\Jobs\AggregateUsageEventsJob;
use App\Services\BillingPeriod;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AggregateUsage extends Command
{
    protected $signature = 'usage:aggregate {--month= : Optional YYYY-MM filter}';

    protected $description = 'Queue chunked aggregation of unprocessed usage events';

    public function handle()
    {
        $month = $this->option('month') ?: null;
        if ($month !== null) {
            try {
                BillingPeriod::forMonth($month);
            } catch (InvalidArgumentException $exception) {
                $this->error($exception->getMessage());

                return 1;
            }
        }

        AggregateUsageEventsJob::dispatch($month, false);
        $this->info('Queued usage aggregation.');

        return 0;
    }
}
