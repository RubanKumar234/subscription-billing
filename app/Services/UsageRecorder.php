<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class UsageRecorder
{
    public function record(int $merchantId, array $data): array
    {
        $occurredAt = Carbon::parse($data['occurred_at'])->utc()->format('Y-m-d H:i:s.u');

        $existing = DB::table('usage_events')
            ->where('merchant_id', $merchantId)
            ->where('event_id', $data['event_id'])
            ->first();
        if ($existing) {
            return $this->duplicateResult($existing, $data, $occurredAt);
        }

        return DB::transaction(function () use ($merchantId, $data, $occurredAt) {
            $subscription = DB::table('subscriptions as subscription')
                ->join('customers as customer', 'customer.id', '=', 'subscription.customer_id')
                ->where('customer.id', $data['customer_id'])
                ->where('customer.merchant_id', $merchantId)
                ->where('subscription.started_at', '<=', $occurredAt)
                ->where(function ($query) use ($occurredAt) {
                    $query->whereNull('subscription.ended_at')
                        ->orWhere('subscription.ended_at', '>', $occurredAt);
                })
                ->select('subscription.id')
                ->sharedLock()
                ->first();

            if (! $subscription) {
                return ['status' => 422, 'body' => ['message' => 'No subscription applies at occurred_at.']];
            }

            $monthStart = Carbon::parse($occurredAt, 'UTC')->startOfMonth()->format('Y-m-d H:i:s.u');
            $monthEnd = Carbon::parse($occurredAt, 'UTC')->startOfMonth()->addMonth()->format('Y-m-d H:i:s.u');
            if (DB::table('invoices')->where('subscription_id', $subscription->id)
                ->where('period_start', $monthStart)->where('period_end', $monthEnd)
                ->where('status', 'finalized')->exists()) {
                return ['status' => 409, 'body' => ['message' => 'The billing period is finalized.']];
            }

            $segment = DB::table('subscription_segments')
                ->where('subscription_id', $subscription->id)
                ->where('starts_at', '<=', $occurredAt)
                ->where(function ($query) use ($occurredAt) {
                    $query->whereNull('ends_at')->orWhere('ends_at', '>', $occurredAt);
                })
                ->orderByDesc('starts_at')
                ->first();

            if (! $segment) {
                return ['status' => 422, 'body' => ['message' => 'No subscription plan applies at occurred_at.']];
            }

            try {
                $id = DB::table('usage_events')->insertGetId([
                    'merchant_id' => $merchantId,
                    'customer_id' => $data['customer_id'],
                    'subscription_segment_id' => $segment->id,
                    'event_id' => $data['event_id'],
                    'occurred_at' => $occurredAt,
                    'units' => $data['units'],
                    'created_at' => Carbon::now('UTC'),
                ]);

                return ['status' => 201, 'body' => ['id' => $id, 'event_id' => $data['event_id'], 'duplicate' => false]];
            } catch (QueryException $exception) {
                if ($exception->getCode() !== '23000' || (int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                    throw $exception;
                }
            }

            $existing = DB::table('usage_events')
                ->where('merchant_id', $merchantId)
                ->where('event_id', $data['event_id'])
                ->lockForUpdate()
                ->first();

            return $this->duplicateResult($existing, $data, $occurredAt);
        }, 3);
    }

    private function duplicateResult($existing, array $data, string $occurredAt): array
    {
        if ($existing &&
            (int) $existing->customer_id === (int) $data['customer_id'] &&
            (int) $existing->units === (int) $data['units'] &&
            $existing->occurred_at === $occurredAt) {
            return ['status' => 200, 'body' => ['id' => $existing->id, 'event_id' => $data['event_id'], 'duplicate' => true]];
        }

        return ['status' => 409, 'body' => ['message' => 'Event ID already exists with different data.']];
    }
}
