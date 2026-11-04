# Subscription Billing and Usage Metering

Laravel 8 / PHP 7.4 backend for multi-tenant subscription billing. Merchants own plans and customers. Usage events are aggregated by day and billed each UTC calendar month, including mid-cycle plan changes.

## Quick start

This is a shared-database, merchant-scoped backend. The merchant API key selects the tenant; usage from another merchant's customer is rejected, and the dashboard returns only the authenticated merchant's data. The dashboard wireframe is represented by a JSON API rather than a browser page.

1. Install PHP 7.4 with `bcmath`, `pdo_mysql`, and `redis`, plus Composer, MySQL, and Redis. Laragon is optional.
2. Create the MySQL databases `subscriptionbilling` and `subscriptionbilling_test`.
3. From the project directory, prepare the application:

   ```powershell
   composer install
   Copy-Item .env.example .env
   php artisan key:generate
   ```

4. Set the MySQL credentials in `.env`, start MySQL and Redis, then run:

   ```powershell
   php artisan config:clear
   php artisan migrate
   php artisan db:seed --class=DemoBillingSeeder
   php artisan serve --host=127.0.0.1 --port=8000
   ```

5. Keep the server open. In another terminal, start `php artisan queue:work redis --tries=3`. A worker waits for jobs until stopped. For a one-time demo, use `php artisan queue:work redis --stop-when-empty --tries=3`.
6. The seeder creates two merchants with separate plans, customers, and subscriptions. Issue a key for the first merchant with `php artisan merchant:issue-key 1`. Use the merchant ID printed by the seeder if it differs from `1`.
7. Run `& .\scripts\Test-Usage.ps1 -CustomerId 1` and paste the key. Use the customer ID printed by the seeder if it differs from `1`. The first request creates an event; the identical retry reports `duplicate: true` with the same database ID.
8. Run `php artisan usage:aggregate`, let the worker process the job, and run `php artisan billing:close` for the previous completed UTC month. The close command queues aggregation before invoice generation and is safe to retry.
9. Request the dashboard with the same key:

   ```powershell
   $key = Read-Host 'Paste merchant API key'
   Invoke-RestMethod -Method Get -Uri 'http://127.0.0.1:8000/merchants/1/dashboard' -Headers @{ 'X-Merchant-Key' = $key }
   ```

   For a tenant-isolation demo, issue a separate key for the second merchant using its seeder ID. Its dashboard shows Gamma Customer's usage. The first merchant's key receives `403` when requesting the second merchant's dashboard, and the second merchant's key cannot submit usage for Acme or Beta.

10. Run `php artisan test`. `phpunit.xml` uses `subscriptionbilling_test`, array cache, and a synchronous queue, so the automated tests do not need the live Redis worker. The suite passed all 18 tests on PHP 7.4 during local verification.

Amounts in API responses and database rows are in minor currency units: `6140` INR means ₹61.40. Do not commit `.env` or share a raw merchant API key.

To verify aggregation and invoicing in MySQL, inspect these tables after the worker finishes:

```sql
SELECT id, event_id, units, processed_at FROM usage_events ORDER BY id DESC LIMIT 10;
SELECT subscription_segment_id, usage_date, units FROM daily_usage ORDER BY usage_date DESC LIMIT 10;
SELECT id, subscription_id, period_start, period_end, total_minor FROM invoices ORDER BY id DESC;
SELECT invoice_id, type, quantity, unit_rate_minor, amount_minor FROM invoice_lines ORDER BY invoice_id, id;
```

## Requirements

- PHP 7.4 with `bcmath`, `pdo_mysql`, and `redis` extensions; Laragon is optional
- MySQL (configure credentials in `.env`)
- Redis for cache and queued jobs
- Composer for dependencies

If `php -v` shows another version, select PHP 7.4 in Laragon or invoke your PHP 7.4 executable directly. Use a PHP 7.4-compatible Composer runtime as well.

## Implemented features

- `POST /usage`: merchant API key, tenant checks, rate limit, and database-enforced idempotency.
- 500-event queued aggregation batches and 100-subscription invoice batches.
- Monthly invoices with prorated base prices and allowances; upgrades and downgrades use immutable pricing segments.
- Redis plan-pricing cache with invalidation when a plan is saved or deleted through Eloquent.
- `GET /merchants/{id}/dashboard`: top five customers, projected overage, and usage drops greater than 50% month over month.
- Repeatable demo data and a separate MySQL test database.
- Eloquent models for merchant, plan, customer, subscription, segment, usage event, daily total, invoice, invoice line, and API key queries. Daily aggregation retains one atomic MySQL upsert through the `DailyUsage` model connection to avoid lost increments under concurrent workers.

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

The schema adds `plans`, `customers`, `subscriptions`, `subscription_segments`, `usage_events`, `daily_usage`, `invoices`, and `invoice_lines` to the existing `merchants` table. `subscription_segments` preserve the plan price, allowance, overage rate, currency, and cycle that applied during each period, so later plan edits do not change historical billing. Cycle and segment intervals use inclusive starts and exclusive ends.

Amounts are stored in minor currency units, except `overage_rate_minor` which uses `DECIMAL(18,6)` to allow fractional minor units per usage unit. The billing service rounds calculated invoice-line amounts. All event and segment times are UTC.

The unique `(merchant_id, event_id)` key prevents duplicate usage events, the unique `(subscription_segment_id, usage_date)` key prevents duplicate daily totals, and the unique invoice subscription/period key prevents duplicate cycle invoices. Aggregation updates daily totals and marks raw events processed in one transaction so a retried job does not double-count.

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

Run `php artisan db:seed --class=DemoBillingSeeder`. It prints IDs for both demo merchants, their plans, customers, and subscriptions. The seeder is repeatable; the first merchant has an upgrade and usage in the previous and current months, while the second merchant has its own current-month usage. Issue a key with `php artisan merchant:issue-key <merchant_id>`, then use that key in the `X-Merchant-Key` header for both endpoints. Only the key hash is stored; use HTTPS outside local development.

In PowerShell, `& .\scripts\Test-Usage.ps1 -CustomerId <customer_id>` prompts for the key, submits a new event, and retries the identical request. It prints the exact URL and both JSON responses. Start `php artisan serve --host=127.0.0.1 --port=8000` in another terminal first.

Run `php artisan usage:aggregate` to queue daily aggregation, then process the Redis queue. Run `php artisan billing:close YYYY-MM` for a completed month and process the queue again. Month close aggregates remaining events before invoicing. The command defaults to the previous UTC month when no month is given. Repeating it does not duplicate invoices.

Other commands: `php artisan subscription:start <customer_id> <plan_id> [ISO-8601-time]` and `php artisan subscription:change-plan <subscription_id> <plan_id> [ISO-8601-time]`. Both copy cached plan prices into immutable subscription segments. A plan change is rejected if later usage already exists or the period has been finalized.

In the November 2026 demo, Acme Customer's October invoice is ₹61.40: ₹15.00 old-plan base, ₹9.00 old-plan overage, ₹32.00 new-plan base, and ₹5.40 new-plan overage. The demo chooses the previous month at run time, so amounts will differ in other months.

`GET /merchants/{id}/dashboard` requires the same key and returns `403` for another merchant's ID. It reads `daily_usage`, so figures become current after aggregation. The response uses minor currency units for projected revenue.

## Billing and reporting assumptions

- Cycles are UTC calendar months with inclusive starts and exclusive ends. Monthly plans are supported. Event timestamps require an explicit timezone and are stored in UTC.
- For each plan segment, base price is multiplied by active seconds divided by cycle seconds, then rounded half up to a minor currency unit. Included units use the same fraction and are rounded down to whole units.
- Overage is calculated separately for each segment as `max(0, segment usage - prorated included units) × that segment's overage rate`, then rounded half up. BCMath avoids floating-point money calculations. Taxes, discounts, credits, refunds, and payments are outside the implemented scope.
- Segment prices, allowance, currency, and rate are snapshots. Editing a plan does not change past or current segments. Merchant and plan currency must match.
- A new event for a finalized month is rejected; an identical retry still succeeds. This avoids silently changing a final invoice. A production system could add adjustment invoices for late usage.
- Churn compares current month-to-date daily totals with the same number of calendar days in the previous month. Previous usage must be positive, and the drop must be strictly greater than 50%.
- Projected overage extrapolates each active segment's aggregated usage pace across its remaining scheduled time; completed segments use actual usage. It is an estimate and is eventually consistent.

## Architecture and trade-offs

Raw `usage_events` are the source of truth. The API key identifies the merchant, and `(merchant_id, event_id)` is unique. Ingestion takes a short shared subscription lock so invoice finalization cannot race a committed event. Aggregation selects at most 500 unprocessed events, increments daily totals, and marks exactly those events processed in one transaction. Invoice generation holds an exclusive subscription lock, refuses unaggregated usage, and writes one unique invoice per subscription and month. Month-close jobs page through subscriptions 100 at a time.

`PlanPricingRepository` caches plan lookups in Redis for 10 minutes using merchant and plan IDs in the key. Saving/deleting `Plan` through Eloquent invalidates the entry; all plan edits must use that model or explicitly invalidate the key. `subscription_segments` keep snapshots so invoices are stable regardless of cache state.

Tests cover duplicate/conflicting events, tenant access, rate limiting, exact plan-change boundaries, 500-event aggregation batches, retry-safe month close, mid-month proration, upgrades, downgrades, exact allowance, fractional-rate rounding, cache invalidation, dashboard metrics, and finalized-period behavior.

The scope is monthly billing with API and command-line workflows. Payments, tax, refunds, adjustment invoices, and a browser dashboard are not implemented. The dashboard loads one merchant's overlapping segments to project revenue; a much larger merchant would benefit from precomputed projections. This project uses PHP 7.4, Laravel 8, and the bundled Redis 3.2 installation for local development; supported versions should be used for production.
