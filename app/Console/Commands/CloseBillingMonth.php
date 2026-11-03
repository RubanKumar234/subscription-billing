<?php

namespace App\Console\Commands;

use App\Jobs\AggregateUsageEventsJob;
use App\Services\BillingPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;

class CloseBillingMonth extends Command
{
    protected $signature = 'billing:close {month? : Calendar month in YYYY-MM}';

    protected $description = 'Queue aggregation and invoice generation for a completed calendar month';

    public function handle()
    {
        $month = $this->argument('month') ?: CarbonImmutable::now('UTC')->subMonth()->format('Y-m');

        try {
            [, $end] = BillingPeriod::forMonth($month);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return 1;
        }

        if ($end->greaterThan(CarbonImmutable::now('UTC'))) {
            $this->error('The billing month has not ended.');

            return 1;
        }

        AggregateUsageEventsJob::dispatch($month, true);
        $this->info('Queued billing close for '.$month.'. Keep the Redis worker running.');

        return 0;
    }
}
