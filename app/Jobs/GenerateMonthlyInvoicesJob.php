<?php

namespace App\Jobs;

use App\Services\BillingPeriod;
use App\Services\InvoiceGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class GenerateMonthlyInvoicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = 30;

    private $month;
    private $afterId;

    public function __construct(string $month, int $afterId = 0)
    {
        $this->month = $month;
        $this->afterId = $afterId;
    }

    public function handle(InvoiceGenerator $generator)
    {
        [$start, $end] = BillingPeriod::forMonth($this->month);
        $subscriptions = DB::table('subscriptions')
            ->where('id', '>', $this->afterId)
            ->where('started_at', '<', $end->format('Y-m-d H:i:s.u'))
            ->where(function ($query) use ($start) {
                $query->whereNull('ended_at')
                    ->orWhere('ended_at', '>', $start->format('Y-m-d H:i:s.u'));
            })
            ->orderBy('id')
            ->limit(100)
            ->pluck('id');

        foreach ($subscriptions as $subscriptionId) {
            $generator->generate((int) $subscriptionId, $this->month);
        }

        if ($subscriptions->count() === 100) {
            self::dispatch($this->month, (int) $subscriptions->last());
        }
    }
}
