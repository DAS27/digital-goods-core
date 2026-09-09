# Implementation contract (shared by agents)

Runtime: PHP >=8.2, PostgreSQL 16+, native PDO and cURL, PSR-4 `App\\` -> `src/`. No framework necessary for this small API; Composer for autoload and quality tooling only. `bootstrap.php` provides autoload. Root owns schema, configuration, infrastructure, catalogue/reconciliation, scripts/CLI, README and Compose.

## Shared helpers (root implements)

- `App\Infrastructure\Database::connect(): PDO`, `::transaction(PDO $db, callable $callback): mixed` (callback receives PDO); transactions do not nest.
- `App\Infrastructure\Log::write(string $event, array $context = []): void` JSON stderr, redact secrets/codes.
- `App\Infrastructure\Ledger::payment(PDO $db, array $order): void`, `::delivery(PDO $db, array $order): void`: called INSIDE existing transaction, idempotent per kind/order; balanced postings.
- `App\Infrastructure\Config::get(string $key, string $default = ''): string`.
- `App\Http\ApiException` agent orders owns (status and safe message).
- `App\Domain\CatalogService(PDO $db)->list(array $query): array`; root.
- `App\Domain\ReconciliationService(PDO $db)->report(): array`, `->recover(): array`; root.

## Public REST (orders agent owns public/index.php and src/Http/*)

- POST `/orders` JSON `{"sku":"STEAM-TOPUP-500","order_id":"optional-client-id"}`. Server generates order_id if omitted. 201 first creation; same id + same SKU 200 replay; different SKU 409. Price always from catalog. 404 unknown SKU, 422 invalid data.
- GET `/orders/{id}` -> order JSON with `order_id, sku, status, amount` (major units), `amount_minor`, `currency`, `paid_at`, `delivery` (`null` or `{provider,request_id,code}`).
- POST `/webhook/payment` and `/webhooks/payment`: exact source contract `event_id, order_id, status: paid|failed, amount` in RUB major units, `currency, created_at` ISO8601. 200 after durable commit, duplicate no-op; conflicting same event_id content 409; invalid format 422. Unknown order -> durable pending inbox, replay on order creation under the SAME advisory transaction lock as webhook.
- A verified paid event is monotonic financial truth. Failed never revokes paid/delivered; a subsequent paid can recover payment_failed (document this interpretation of out-of-order events). Amount/currency mismatch is saved as rejected, never paid; return 200 with outcome rejected.
- GET `/catalog?type=key&limit=50&after_price=...&after_sku=...`: root catalogue service.
- GET `/health`: DB readiness.
- No real payment signature or user authentication required by assignment. Do not expose reconciliation, supplier controls, or restock publicly via API; CLI only. Bind demo ports to localhost.

Services owned by orders agent: `OrderService(PDO $db)->create(array $input): array` (include `_created` bool for HTTP to strip), `->get(string $id): array`; `PaymentService(PDO $db)->receive(array $input): array`, `->replayPending(string $orderId): void` (inside caller transaction). Serialize order creation / payment with `pg_advisory_xact_lock(hashtextextended('payment:' || :id, 0))` before touching event/order rows, including when no order exists. Payload hash uses normalized known fields; validate money without unsafe float arithmetic.

## Database tables (exact names)

- `products(sku text PK, name text, type text, price_minor bigint, currency char(3), available_stock int default 0, active bool default true)`.
- `orders(id text PK, sku text FK, amount_minor bigint, currency char(3), status text, paid_at timestamptz null, created_at timestamptz default now(), updated_at timestamptz default now())`.
- `payment_events(event_id text PK, order_id text [no FK, early events], status text, amount_minor bigint, currency char(3), occurred_at timestamptz, payload_hash text, state text default 'pending' [pending/applied/ignored/rejected], reason text null, created_at timestamptz default now(), processed_at timestamptz null)`.
- `delivery_jobs(order_id text PK FK, attempts int default 0, available_at timestamptz default now(), last_error text null, created_at timestamptz default now())`. UPSERT once in payment transaction. Do not delete except after delivery. Backoff lives on job.
- `delivery_attempts(id bigserial PK, order_id text FK, provider text A|B, request_id text unique, state text pending|in_flight|ambiguous|succeeded|rejected, reason text null, calls int default 0, created_at/updated_at timestamptz default now())`. Partial unique one active attempt per order (pending/in_flight/ambiguous). Terminal rejections permit a NEW request_id on a later restock cycle. Never new id on ambiguous outcome.
- `deliveries(order_id text PK FK, provider text, request_id text unique FK delivery_attempts, code text unique, created_at timestamptz default now())`.
- `ledger_transactions(id bigserial PK, order_id FK, kind payment|delivery, currency char(3), created_at, unique(order_id,kind))`; `ledger_entries(id bigserial PK, transaction_id FK, account text cash|customer_liability|revenue, amount_minor bigint signed)`. Deferred DB constraint balances every transaction. Payment cash +amount, liability -amount; delivery liability +amount, revenue -amount.

Supplier-owned data in independent schema `supplier` (app never reads during business delivery):

- `supplier.stock(id bigserial PK, provider A|B, sku text, code text unique, request_id text unique null)`.
- `supplier.requests(provider text, request_id text, order_id text, sku text, state issued|rejected, code text null, reason text null, created_at timestamptz default now(), PK(provider,request_id))`. UNIQUE `(provider, order_id)` WHERE state='issued'. Every error classified definitive MUST be persisted immutable here, including unavailable and out_of_stock. Thus a late/replayed request cannot issue after rejection. Same request_id different order/sku -> conflict, no key.
- `supplier.settings(provider PK, mode normal|unavailable|out_of_stock|timeout_after_issue|timeout_before_issue|random, error_rate double precision default 0, timeout_rate double precision default 0, delay_ms int default 1200)`.
- `supplier.calls(id bigserial PK, provider, request_id, order_id, created_at default now())` for test audit (do not store codes in logs).

## Supplier and worker (suppliers agent owns src/Supplier/*, src/Delivery/*, supplier/index.php)

- Real HTTP POST `/issue` runs two separate processes selected by `SUPPLIER_NAME=A|B`. Same DB service acceptable for demo but separate schema and connections, no cross-boundary transaction. Root supplies schema/seed.
- Stub must commit request/code before simulated response timeout; repeated request returns SAME committed outcome immediately, even if mode changes. Serialize request and order at supplier. Timeout_before_issue must model actual delayed issue, so pin same ID.
- Definitive error response extends source error contract with `request_id` and `definitive:true`. Immutable unavailable/out_of_stock rejection guarantees no later issue for ID. Generic 5xx/timeout/bad response is ambiguous. A failure to connect BEFORE any potentially sent attempt may safely reject locally; if hard to prove, keep ambiguous. Document chosen rule.
- `App\Delivery\DeliveryWorker(PDO $db)->tick(): bool` does one due job (true if worked), ->`run` optional. Session advisory `pg_try_advisory_lock(hashtextextended('delivery:' || :id,0))` serializes workers. Dedicated persistent PDO for tick; MUST release in finally. Never keep SQL transaction open during network request. Session pooling required, not PgBouncer transaction pooling.
- Store attempt and mark in_flight BEFORE HTTP. Process crash leaves in_flight; next worker retries same supplier/request_id. Row order lock + transaction atomically commits deliveries, delivered status, ledger, job delete. Worker only acts if paid_at exists. Claim candidate, acquire lock, RECHECK eligibility/due time.
- Timeouts/unknown results -> state ambiguous, recoverable delivery_failed, same supplier & request_id next time; NEVER fallback. Known persisted rejection from A -> B. Both definitive failures -> recoverable out_of_stock iff both out_of_stock, otherwise delivery_failed; new safe cycle after backoff.
- Env: `DATABASE_DSN=pgsql:host=db;port=5432;dbname=goods`, `DATABASE_USER=goods`, `DATABASE_PASSWORD=goods`, `SUPPLIER_A_URL=http://supplier-a:8081`, `SUPPLIER_B_URL=http://supplier-b:8082`, `SUPPLIER_TIMEOUT_MS=300`, `BACKOFF_BASE_MS=200`, `BACKOFF_MAX_MS=30000`. Tests may override base to 30ms.

## Test harness (test agent owns tests/* and scripts/test.sh only)

Use Python3 stdlib unittest + HTTP to running servers; avoid third party dependencies. DB inspection/control via `php bin/console ...` subprocess (root implements) and JSON output. Running tests inside app/test container uses APP_URL=http://api:8080 (can override). Each test unique SKU/order namespace; do not wipe user data. Test-only controls need APP_ENV=test; test harness fail closed otherwise.

`php bin/console test:sql '<SQL>'` with APP_ENV=test yields JSON list of rows for SELECT/RETURNING (root implements; SQL string passed as argv, never shell interpolation). SQL can configure supplier.settings and add test products/stock or inspect invariants. Tests run serially because global settings; workers/API run concurrently.
`php bin/console worker:once` tick one job, `worker:run` continuous.
`php bin/console recover` safely recreates missing jobs / expedites existing without changing active request id. `reconcile` JSON report.
Test at least 50 parallel same event, 50 distinct same order, event_id conflict, pre-order + creation race, out-of-order paid/failed, mismatched money, post-commit timeout (supplier issuance count, same request_id), A persisted unavailable fallback B, both unavailable -> recovery, out_of_stock -> restock recovery, multiple workers, multiple orders distinct keys, malformed API, money ledger balance, job restoration. Add crash-window test if feasible using state seeding of already issued supplier + in_flight attempt.
Use deterministic fault modes for acceptance, random configurable modes as additional scenario. Wait bounded with useful diagnostics. Print number of tests/time.
