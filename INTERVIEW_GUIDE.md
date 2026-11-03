# Interview walkthrough

Use this as a speaking outline for a narrated 5–10 minute recording. Explain the choices in your own words and demonstrate the running app.

## 1. Problem and assumptions (about 1 minute)

“This is a multi-tenant usage-metered billing backend. Each merchant owns plans and customers. I chose UTC calendar-month cycles because the brief asks for proration when a subscription starts mid-cycle. I treat period starts as inclusive and ends as exclusive so an event on a plan-change boundary belongs to one segment.”

Show the Billing and reporting assumptions section in `README.md`.

## 2. Schema and scaling (about 1 minute)

Show the migrations. Explain that `usage_events` is the auditable source of truth, while `daily_usage` is a derived summary for billing and the dashboard. `subscription_segments` snapshot pricing at each change. Point out the unique `(merchant_id, event_id)` key and the invoice subscription/period key. For 5 million rows, mention chunking, composite indexes, query-plan measurement, and why MySQL 5.7 foreign keys complicate native partitioning.

## 3. Usage API (about 1 minute)

Issue or use a merchant key. Submit `POST /usage` with one event, repeat it unchanged, then repeat the ID with different units. Show `201`, `200 duplicate`, and `409`. Explain that the key chooses the tenant, the database unique index enforces idempotency under concurrency, and rate limiting is 60 requests/minute.

## 4. Queue and aggregation (about 1 minute)

Run `php artisan usage:aggregate`, then `php artisan queue:work redis --stop-when-empty`. Show the raw event's `processed_at` and the `daily_usage` row. Explain that a 500-event batch updates totals and marks events processed in one transaction, so retries do not double-count.

## 5. Billing and plan changes (about 2 minutes)

Show an old and a new subscription segment, then run `php artisan billing:close YYYY-MM` for a completed month and process the queue. Show the invoice and its lines. Explain the segment calculation: prorated base, prorated allowance, segment usage, segment overage rate. In the November 2026 demo, the October invoice is ₹61.40: ₹15.00 + ₹9.00 on Basic, then ₹32.00 + ₹5.40 on Growth. Show that running month close again keeps one invoice.

## 6. Dashboard, cache, tests, and trade-offs (about 2 minutes)

Request `GET /merchants/{id}/dashboard`. Point to the top five, projected overage, and customers with more than 50% month-to-date usage drop. Explain that the dashboard reads daily totals, so it reflects completed aggregation. Show `PlanPricingRepository` and the `Plan` model's invalidation hook. Run `php artisan test`. Finish with the trade-offs: monthly cycles, no tax/payments, API rather than polished UI, and the chosen older stack for this exercise.

## Questions you may get

- **Why store plan prices in segments?** A later plan edit must not rewrite historical charges.
- **Why both raw events and daily totals?** Raw events support audit/replay; daily totals make reports and invoices cheaper.
- **How do retries stay safe?** Unique event IDs, transactional aggregation, and unique invoice periods.
- **What if usage arrives after finalization?** A new event is rejected; an identical retry still succeeds. A production system could add adjustment invoices.
- **How is allowance handled on upgrade?** Each segment gets a time-prorated allowance, and overage uses that segment's rate.
- **How would you scale further?** Measure indexes and write throughput; archive old raw events; redesign for partitioning if volume warrants it.
- **Why PHP 7.4/Laravel 8?** This was the chosen local stack for the exercise. State clearly that you would choose supported versions for production.

Before submitting, include real screenshots of the AI prompts you used in `prompts/`, create/share a private GitHub repository, and record the narrated walkthrough yourself.

## File map by implementation step

1. **Project and configuration:** `composer.json`, `composer.lock`, `.env.example`, `phpunit.xml`, `app/Http/Kernel.php`, `app/Providers/RouteServiceProvider.php`, `app/Console/Kernel.php`, `routes/web.php`, `routes/api.php`, and `routes/usage.php`. The remaining base Laravel files are the standard framework scaffold.
2. **Database design:** `database/migrations/2026_11_03_094046_create_merchants_table.php`, `094430_create_plans_table.php`, `094431_create_customers_table.php`, `094432_create_subscriptions_table.php`, `094433_create_subscription_segments_table.php`, `094434_create_usage_events_table.php`, `094435_create_daily_usage_table.php`, `094436_create_invoices_table.php`, `094437_create_invoice_lines_table.php`, `094438_add_currency_to_merchants_table.php`, `094439_create_merchant_api_keys_table.php`, and `094440_index_usage_events_for_aggregation.php` (all with the `2026_11_03_` prefix). Their Eloquent models are under `app/Models/`.
3. **Usage API and tenant safety:** `app/Http/Requests/StoreUsageRequest.php`, `app/Http/Middleware/AuthenticateMerchantApiKey.php`, `app/Http/Controllers/UsageController.php`, `app/Services/UsageRecorder.php`, `app/Console/Commands/IssueMerchantApiKey.php`, and `routes/usage.php`.
4. **Chunked aggregation and month close:** `app/Services/UsageAggregator.php`, `app/Jobs/AggregateUsageEventsJob.php`, `app/Jobs/GenerateMonthlyInvoicesJob.php`, `app/Console/Commands/AggregateUsage.php`, `app/Console/Commands/CloseBillingMonth.php`, and `app/Console/Kernel.php`.
5. **Billing and plan changes:** `app/Services/BillingPeriod.php`, `app/Services/BillingMath.php`, `app/Services/InvoiceGenerator.php`, `app/Services/SubscriptionManager.php`, `app/Services/PlanPricingRepository.php`, `app/Models/Plan.php`, `app/Console/Commands/StartSubscription.php`, and `app/Console/Commands/ChangeSubscriptionPlan.php`.
6. **Dashboard:** `app/Services/MerchantDashboard.php`, `app/Http/Controllers/MerchantDashboardController.php`, and `routes/usage.php`.
7. **Verification and handoff:** `tests/Feature/UsageEndpointTest.php`, `tests/Feature/BillingFlowTest.php`, `tests/Feature/MerchantDashboardTest.php`, `database/seeders/DemoBillingSeeder.php`, `README.md`, this guide, and `prompts/README.md`.
