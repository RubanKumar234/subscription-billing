# Subscription Billing

Laravel 8 / PHP 7.4 backend for a multi-tenant subscription billing exercise. Merchants own plans and customers. Usage events are aggregated by day and billed each UTC calendar month, including mid-cycle plan changes.

## Requirements

- Laragon PHP 7.4.29
- MySQL (configure credentials in `.env`)
- Redis for cache and queued jobs
- Composer for dependencies

## Run locally

1. Start Apache and MySQL in Laragon.
2. Create a MySQL database named `subscriptionbilling`.
3. Check database settings in `.env`.
4. From this directory, run `php artisan migrate`.
5. Open `http://subscriptionbilling.test` if Laragon auto virtual hosts are enabled, or run `php artisan serve`.

Run tests with `php artisan test`. Tests use a separate MySQL database named `subscriptionbilling_test`; create it before running them. For this project, use the PHP 7.4 executable at `D:\work\tools\Laragon\bin\php\php-7.4.29-nts-Win32-vc15-x64\php.exe` when another PHP version is first on PATH. Run `composer install` if dependencies are absent.

## Implemented features

- `POST /usage`: merchant API key, tenant checks, rate limit, and database-enforced idempotency.
- 500-event queued aggregation batches and 100-subscription invoice batches.
- Monthly invoices with prorated base prices and allowances; upgrades and downgrades use immutable pricing segments.
- Redis plan-pricing cache with invalidation when a plan is saved or deleted through Eloquent.
- `GET /merchants/{id}/dashboard`: top five customers, projected overage, and usage drops greater than 50% month over month.
- Repeatable demo data and a separate MySQL test database.

The dashboard wireframe is a visual reference. The required dashboard is delivered as JSON.

## Redis cache and queue

The application is configured with `CACHE_DRIVER=redis`, `QUEUE_CONNECTION=redis`, and `REDIS_CLIENT=phpredis`. PHP 7.4 in Laragon has the Redis extension installed.

1. In Laragon, start Redis (Menu > Redis > Start Redis, or Start All if Redis is enabled).
2. Check that Redis listens on `127.0.0.1:6379`.
3. Run `php artisan config:clear` after changing `.env`.
4. Run `php artisan queue:work redis --tries=3` in a separate terminal and leave it running while testing jobs.
5. After changing queued job code, restart the worker with `php artisan queue:restart`.

The cache uses Redis database 1, while the queue uses the default Redis database 0. Run `php artisan schedule:run` once per minute with a scheduler for automatic aggregation and monthly close. For a local demo, use the commands below.

## Billing schema

The schema adds `plans`, `customers`, `subscriptions`, `subscription_segments`, `usage_events`, `daily_usage`, `invoices`, and `invoice_lines` to the existing `merchants` table. `subscription_segments` preserve the plan price, allowance, overage rate, currency, and cycle that applied during each period, so later plan edits do not change historical billing. Cycle and segment intervals will use inclusive starts and exclusive ends.

Amounts are stored in minor currency units, except `overage_rate_minor` which uses `DECIMAL(18,6)` to allow fractional minor units per usage unit. The billing service will round calculated invoice-line amounts. All event and segment times are UTC.

The unique `(merchant_id, event_id)` key prevents duplicate usage events, the unique `(subscription_segment_id, usage_date)` key prevents duplicate daily totals, and the unique invoice subscription/period key prevents duplicate cycle invoices. Aggregation must update daily totals and mark raw events processed in one transaction so a retried job does not double-count.

For 5 million or more raw events, reads should use the customer/time and aggregation indexes, process events in bounded batches, and use `daily_usage` for billing and dashboard queries. If growth warrants it, consider date partitioning and an archival policy for raw events.

MySQL 5.7 does not allow foreign keys on partitioned InnoDB tables, so date partitioning this schema would require a deliberate redesign. I would measure write latency and query plans before adding more indexes or partitions.

## Record usage

`POST /usage` accepts JSON and requires an `X-Merchant-Key` header. The key identifies the merchant; requests cannot select a different tenant by supplying a merchant ID. Issue a key for an existing active merchant with `php artisan merchant:issue-key <merchant_id>`. The command prints the raw key once and stores only its SHA-256 hash. Use HTTPS outside local development.

Example request body:

```json
{
  "customer_id": 1,
  "event_id": "api-call-123",
  "occurred_at": "2026-11-03T10:00:00Z",
  "units": 5
}
```

`occurred_at` must include a timezone (`Z` or an offset) and is stored in UTC. The customer must belong to the authenticated merchant and have a subscription segment covering the event time. The segment's start is inclusive and its end is exclusive. The endpoint inserts one raw event and does not perform aggregation in the request.

Responses: `201` for a new event, `200` for an identical retry, `409` if the event ID is reused with different data, `401` for an invalid key, `422` for invalid data or no applicable subscription, and `429` when rate limited. The database unique key `(merchant_id, event_id)` enforces idempotency even for concurrent requests. Laravel applies the existing 60 requests/minute per-IP API limit and a 60 requests/minute per-key limit on this route.

## Demo and operational commands

Run `php artisan db:seed --class=DemoBillingSeeder`. It prints demo merchant, plan, customer, and subscription IDs. The seeder is repeatable; it creates an upgrade and usage in the previous and current months. Issue a key with `php artisan merchant:issue-key <merchant_id>`, then use that key in the `X-Merchant-Key` header for both endpoints. Only the key hash is stored; use HTTPS outside local development.

Run `php artisan usage:aggregate` to queue daily aggregation, then process the Redis queue. Run `php artisan billing:close YYYY-MM` for a completed month and process the queue again. Month close aggregates remaining events before invoicing. The command defaults to the previous UTC month when no month is given. Repeating it does not duplicate invoices.

Other commands: `php artisan subscription:start <customer_id> <plan_id> [ISO-8601-time]` and `php artisan subscription:change-plan <subscription_id> <plan_id> [ISO-8601-time]`. Both copy cached plan prices into immutable subscription segments. A plan change is rejected if later usage already exists or the period has been finalized.

In the November 2026 demo, Acme Customer's October invoice is ₹61.40: ₹15.00 old-plan base, ₹9.00 old-plan overage, ₹32.00 new-plan base, and ₹5.40 new-plan overage. The demo chooses the previous month at run time, so amounts will differ in other months.

`GET /merchants/{id}/dashboard` requires the same key and returns `403` for another merchant's ID. It reads `daily_usage`, so figures become current after aggregation. The response uses minor currency units for projected revenue.

## Billing and reporting assumptions

- Cycles are UTC calendar months with inclusive starts and exclusive ends. Monthly plans are supported. Event timestamps require an explicit timezone and are stored in UTC.
- For each plan segment, base price is multiplied by active seconds divided by cycle seconds, then rounded half up to a minor currency unit. Included units use the same fraction and are rounded down to whole units.
- Overage is calculated separately for each segment as `max(0, segment usage - prorated included units) × that segment's overage rate`, then rounded half up. BCMath avoids floating-point money calculations. Taxes, discounts, credits, refunds, and payments are outside this exercise.
- Segment prices, allowance, currency, and rate are snapshots. Editing a plan does not change past or current segments. Merchant and plan currency must match.
- A new event for a finalized month is rejected; an identical retry still succeeds. This avoids silently changing a final invoice. A production system could add adjustment invoices for late usage.
- Churn compares current month-to-date daily totals with the same number of calendar days in the previous month. Previous usage must be positive, and the drop must be strictly greater than 50%.
- Projected overage extrapolates each active segment's aggregated usage pace across its remaining scheduled time; completed segments use actual usage. It is an estimate and is eventually consistent.

## Architecture and trade-offs

Raw `usage_events` are the source of truth. The API key identifies the merchant, and `(merchant_id, event_id)` is unique. Ingestion takes a short shared subscription lock so invoice finalization cannot race a committed event. Aggregation selects at most 500 unprocessed events, increments daily totals, and marks exactly those events processed in one transaction. Invoice generation holds an exclusive subscription lock, refuses unaggregated usage, and writes one unique invoice per subscription and month. Month-close jobs page through subscriptions 100 at a time.

`PlanPricingRepository` caches plan lookups in Redis for 10 minutes using merchant and plan IDs in the key. Saving/deleting `Plan` through Eloquent invalidates the entry; all plan edits must use that model or explicitly invalidate the key. `subscription_segments` keep snapshots so invoices are stable regardless of cache state.

Tests cover duplicate/conflicting events, tenant access, rate limiting, exact plan-change boundaries, 500-event aggregation batches, retry-safe month close, mid-month proration, upgrades, downgrades, exact allowance, fractional-rate rounding, cache invalidation, dashboard metrics, and finalized-period behavior.

The timebox trade-offs are monthly billing only, API/CLI delivery without a polished dashboard UI, and no payment collection or adjustment-invoice workflow. The dashboard loads one merchant's overlapping segments to project revenue; a much larger merchant would benefit from precomputed projections. PHP 7.4, Laravel 8, and the bundled Redis 3.2 installation are for this local exercise; supported versions should be used for production.

See `INTERVIEW_GUIDE.md` for a speaking outline. Add screenshots of the actual AI prompts used during development under `prompts/` before submission.
