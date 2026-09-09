# Acceptance suite

`scripts/test.sh` runs Python standard-library `unittest` tests against real API
and supplier HTTP processes plus PostgreSQL. It requires `APP_ENV=test` already
set and a dedicated test database. The guarded `test:sql` console command is the
only database access path; SQL is passed directly as a subprocess argument.

Run the suite in the test service described in the main README. For an already
running isolated stack, configure `APP_URL`, `DATABASE_DSN`, `DATABASE_USER`,
`DATABASE_PASSWORD`, `SUPPLIER_A_URL`, and `SUPPLIER_B_URL`, then run:

```sh
APP_ENV=test scripts/test.sh
```

The suite owns worker scheduling. **Stop continuous delivery workers before
running it.** API and both suppliers must support concurrent HTTP requests;
in particular, delayed-request fencing requires more than one supplier worker.
Set `SUPPLIER_TIMEOUT_MS=300` for the worker processes. Tests use real 1.2-second
supplier delays and bounded polling, with diagnostic order/event/attempt/job
state on failure. Key codes are omitted from diagnostic dumps.

Every test creates unique SKUs, orders, event IDs and synthetic keys. The suite
does not truncate tables or delete prior runs. It restores the original global
supplier fault settings afterward. One recovery test deliberately removes its
own newly created delivery job to verify repair; no order, payment, key or
ledger entry is deleted.

## Deterministic coverage

| Scenarios | Assertions |
|---|---|
| 50 parallel identical webhooks | One inbox event, payment posting and physical issuance |
| 50 parallel distinct paid events | One payment effect and delivery despite distinct event IDs |
| Event ID conflicts | HTTP 409 and unchanged original financial event |
| Early event and 12 creation/payment races | Durable pending inbox, replay, no stranded paid order |
| Out-of-order paid/failed events | Failed cannot revoke payment or delivery |
| Wrong amount/currency and money parser boundaries | Audited rejection; no posting or issuance |
| Timeout before and after supplier issuance | Same provider/request ID across retries; no B call |
| Definitive A rejection, both failures, empty stock | Safe fallback and recovery after availability/restock |
| 10 concurrent workers and 10 concurrent orders | One physical issuance per order and distinct keys |
| Crash before HTTP and after supplier commit | Recovery reuses the durable in-flight request |
| Lost delivery job | Repeated recovery recreates one job |
| Reconciliation before and after repair | Pending events, paid orders awaiting delivery, delivery without payment, balanced postings |
| 20 parallel order replays and catalog price change | Stable order identity and original quoted amount |
| Invalid JSON, duplicate fields, body limit, types | Defined client errors without server failures |
| Ledger entries and deferred database constraint | Exact balanced postings; unbalanced write rolls back |
| Supplier ID conflicts | A request cannot be replayed for another order |
| Delayed request versus definitive rejection | Late execution cannot issue after permanent rejection |
| Invalid supplier JSON, unknown 5xx and mismatched ID | Ambiguous result pins the original request |
| Catalog filter, tied prices, cursors and invalid queries | Stable pagination by price/SKU; excludes inactive or empty-stock products |
| Retry backoff without schedule overrides | An immediate tick does no work; retry succeeds after the stored due time |
| SIGKILL after supplier commit | A real worker process dies before receiving the key; recovery repeats the same request and commits one delivery |

An optional stochastic scenario exercises configurable random faults, then
restores normal service and checks the same invariants:

```sh
APP_ENV=test RUN_RANDOM_TESTS=1 scripts/test.sh
```

`TEST_POLL_TIMEOUT` (default 18 seconds) and `TEST_HTTP_TIMEOUT` (15 seconds)
adjust bounded waits for slower machines. `PHP_BIN` and `PYTHON_BIN` select local
executables. `unittest` prints the scenario count, elapsed time and failures.
To rerun a focused scenario after an observed failure:

```sh
APP_ENV=test scripts/test.sh -k timeout
```

The two seeded crash fixtures model persisted state at precise crash windows.
An additional test starts a real worker, waits for the supplier's committed
issuance while its response is delayed, sends SIGKILL, and recovers with a new
worker. This covers an actual process crash as well as controlled persisted
states; it does not prove every failure mode of a real external supplier.
