<?php

namespace App\Jobs;

use App\Services\UsageAggregator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AggregateUsageEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    private $month;
    private $closeMonth;

    public function __construct(?string $month = null, bool $closeMonth = false)
    {
        $this->month = $month;
        $this->closeMonth = $closeMonth;
    }

    public function handle(UsageAggregator $aggregator)
    {
        $processed = $aggregator->processBatch($this->month);

        if ($processed === UsageAggregator::BATCH_SIZE) {
            self::dispatch($this->month, $this->closeMonth);
        } elseif ($this->closeMonth) {
            GenerateMonthlyInvoicesJob::dispatch($this->month);
        }
    }
}
