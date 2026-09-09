"""Black-box acceptance tests. Run serially in the dedicated APP_ENV=test stack.

Only Python's standard library is used. Application behavior crosses real HTTP
boundaries; database inspection and fault controls use the guarded console.
Every scenario gets unique orders, SKUs, events and synthetic keys. Existing
business data is never deleted. Do not run a background delivery worker here:
these tests own scheduling to observe the exact failure and recovery windows.
"""

from __future__ import annotations

import concurrent.futures
import json
import os
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import subprocess
import threading
import time
import unittest
import urllib.error
import urllib.request
from urllib.parse import urlencode
import uuid
from decimal import Decimal
from typing import Any


ROOT = Path(__file__).resolve().parents[1]
APP_URL = os.environ.get("APP_URL", "http://api:8080").rstrip("/")
SUPPLIER_URLS = {
    "A": os.environ.get("SUPPLIER_A_URL", "http://supplier-a:8081").rstrip("/"),
    "B": os.environ.get("SUPPLIER_B_URL", "http://supplier-b:8082").rstrip("/"),
}
PHP_BIN = os.environ.get("PHP_BIN", "php")
POLL_TIMEOUT = float(os.environ.get("TEST_POLL_TIMEOUT", "18"))
HTTP_TIMEOUT = float(os.environ.get("TEST_HTTP_TIMEOUT", "15"))
RUN_ID = "t" + uuid.uuid4().hex[:12]


def quote(value: str) -> str:
    """SQL string literal; SQL is passed as an argv value, never through a shell."""
    return "'" + value.replace("'", "''") + "'"


def console(*args: str, check: bool = True, env: dict[str, str] | None = None) -> Any:
    result = subprocess.run(
        [PHP_BIN, str(ROOT / "bin" / "console"), *args],
        cwd=ROOT,
        env={**os.environ, **(env or {})},
        capture_output=True,
        text=True,
        timeout=max(20, HTTP_TIMEOUT * 2),
        check=False,
    )
    if not check:
        return result
    if result.returncode:
        raise AssertionError(
            f"Console {args[0]} exited {result.returncode}: "
            f"{result.stderr[-3000:]} {result.stdout[-3000:]}"
        )
    try:
        return json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError(
            f"Console {args[0]} did not return JSON: {result.stdout[-3000:]}"
        ) from exc


def sql(statement: str) -> list[dict[str, Any]]:
    result = console("test:sql", statement)
    if not isinstance(result, list):
        raise AssertionError(f"test:sql must return JSON rows, got {result!r}")
    return result


def http(
    method: str,
    path: str,
    payload: Any = None,
    *,
    raw: bytes | None = None,
    base: str = APP_URL,
    content_type: str | None = "application/json",
    timeout: float = HTTP_TIMEOUT,
) -> tuple[int, Any]:
    if raw is None and payload is not None:
        raw = json.dumps(payload, separators=(",", ":")).encode()
    headers = {"Accept": "application/json"}
    if content_type is not None and raw is not None:
        headers["Content-Type"] = content_type
    request = urllib.request.Request(
        base + path, data=raw, headers=headers, method=method
    )
    try:
        response = urllib.request.urlopen(request, timeout=timeout)
    except urllib.error.HTTPError as exc:
        response = exc
    with response:
        body = response.read()
        status = response.status
    try:
        parsed = json.loads(body)
    except json.JSONDecodeError:
        parsed = body[:1000].decode(errors="replace")
    return status, parsed


def parallel(functions: list[Any]) -> list[Any]:
    """A barrier makes the advertised parallel request count meaningful."""
    barrier = threading.Barrier(len(functions))

    def run(function: Any) -> Any:
        barrier.wait(timeout=HTTP_TIMEOUT)
        return function()

    with concurrent.futures.ThreadPoolExecutor(max_workers=len(functions)) as pool:
        return list(pool.map(run, functions))


class Acceptance(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        if os.environ.get("APP_ENV") != "test":
            raise RuntimeError(
                "Acceptance tests require APP_ENV=test and an isolated database. "
                "The test suite refuses to enable test controls itself."
            )
        deadline = time.monotonic() + 30
        last_error: Any = None
        while time.monotonic() < deadline:
            try:
                status, body = http("GET", "/health", timeout=2)
                if status == 200:
                    break
                last_error = (status, body)
            except (OSError, urllib.error.URLError) as exc:
                last_error = str(exc)
            time.sleep(0.15)
        else:
            raise RuntimeError(f"API not ready at {APP_URL}: {last_error}")
        sql("SELECT 1 AS ready")
        cls.original_settings = sql(
            "SELECT provider, mode, error_rate, timeout_rate, delay_ms "
            "FROM supplier.settings ORDER BY provider"
        )
        if {row["provider"] for row in cls.original_settings} != {"A", "B"}:
            raise RuntimeError("Both supplier settings must be seeded before testing")

    @classmethod
    def tearDownClass(cls) -> None:
        for row in getattr(cls, "original_settings", []):
            sql(
                "UPDATE supplier.settings SET "
                f"mode={quote(row['mode'])},error_rate={float(row['error_rate'])},"
                f"timeout_rate={float(row['timeout_rate'])},delay_ms={int(row['delay_ms'])} "
                f"WHERE provider={quote(row['provider'])} RETURNING provider"
            )

    def setUp(self) -> None:
        self.namespace = RUN_ID + "-" + uuid.uuid4().hex[:10]
        self.orders: list[str] = []
        self.counter = 0
        self.mode("normal")

    def tearDown(self) -> None:
        self.mode("normal")

    def new_id(self, purpose: str = "id") -> str:
        self.counter += 1
        return f"{self.namespace}-{purpose}-{self.counter}"

    def mode(self, mode: str, provider: str | None = None, **overrides: Any) -> None:
        changes = {
            "mode": mode,
            "error_rate": 0,
            "timeout_rate": 0,
            "delay_ms": 1200,
            **overrides,
        }
        assignments = ",".join(
            f"{key}={quote(value) if isinstance(value, str) else value}"
            for key, value in changes.items()
        )
        condition = f" WHERE provider={quote(provider)}" if provider else ""
        sql(f"UPDATE supplier.settings SET {assignments}{condition} RETURNING provider")

    def product(self, *, price: int = 1999, stock_a: int = 4, stock_b: int = 4) -> str:
        sku = self.new_id("sku").upper()
        sql(
            "INSERT INTO products(sku,name,type,price_minor,currency,available_stock) "
            f"VALUES({quote(sku)},'Acceptance synthetic key','key',{price},'RUB',"
            f"{stock_a + stock_b}) RETURNING sku"
        )
        self.restock(sku, "A", stock_a, update_catalog=False)
        self.restock(sku, "B", stock_b, update_catalog=False)
        return sku

    def restock(self, sku: str, provider: str, count: int, *, update_catalog: bool = True) -> None:
        if not count:
            return
        values = ",".join(
            f"({quote(provider)},{quote(sku)},{quote(self.new_id('key'))})"
            for _ in range(count)
        )
        sql(f"INSERT INTO supplier.stock(provider,sku,code) VALUES {values} RETURNING id")
        if update_catalog:
            sql(
                f"UPDATE products SET available_stock=available_stock+{count} "
                f"WHERE sku={quote(sku)} RETURNING sku"
            )

    def create(self, sku: str, order_id: str | None = None) -> dict[str, Any]:
        order_id = order_id or self.new_id("order")
        self.orders.append(order_id)
        status, body = http("POST", "/orders", {"order_id": order_id, "sku": sku})
        self.assertEqual(status, 201, body)
        self.assertEqual(body["order_id"], order_id)
        return body

    def event(self, order_id: str, **overrides: Any) -> dict[str, Any]:
        return {
            "event_id": self.new_id("event"),
            "order_id": order_id,
            "status": "paid",
            "amount": 19.99,
            "currency": "RUB",
            "created_at": "2026-09-08T10:00:00.123456Z",
            **overrides,
        }

    def pay(self, order_id: str, **overrides: Any) -> dict[str, Any]:
        payload = self.event(order_id, **overrides)
        status, body = http("POST", "/webhook/payment", payload)
        self.assertEqual(status, 200, body)
        return payload

    def order(self, order_id: str) -> dict[str, Any]:
        status, body = http("GET", "/orders/" + order_id)
        self.assertEqual(status, 200, body)
        return body

    def count(self, table: str, condition: str) -> int:
        return int(sql(f"SELECT count(*) AS n FROM {table} WHERE {condition}")[0]["n"])

    def order_count(self, table: str, order_id: str) -> int:
        return self.count(table, "order_id=" + quote(order_id))

    def attempts(self, order_id: str) -> list[dict[str, Any]]:
        return sql(
            "SELECT provider,request_id,state,reason,calls FROM delivery_attempts "
            f"WHERE order_id={quote(order_id)} ORDER BY id"
        )

    def diagnostic(self, order_id: str) -> str:
        # Synthetic key values are deliberately omitted from diagnostics/logs.
        rows = {
            "order": sql(
                "SELECT id,status,paid_at,amount_minor,currency FROM orders "
                f"WHERE id={quote(order_id)}"
            ),
            "events": sql(
                "SELECT event_id,status,state,reason FROM payment_events "
                f"WHERE order_id={quote(order_id)}"
            ),
            "jobs": sql(
                "SELECT order_id,attempts,available_at,last_error FROM delivery_jobs "
                f"WHERE order_id={quote(order_id)}"
            ),
            "attempts": self.attempts(order_id),
            "supplier": sql(
                "SELECT provider,request_id,state,reason FROM supplier.requests "
                f"WHERE order_id={quote(order_id)}"
            ),
        }
        return json.dumps(rows, ensure_ascii=False, default=str)

    def expedite(self, order_ids: list[str]) -> None:
        ids = ",".join(quote(order_id) for order_id in order_ids)
        sql(f"UPDATE delivery_jobs SET available_at=now() WHERE order_id IN ({ids}) RETURNING order_id")

    def worker_once(self) -> Any:
        return console("worker:once")

    def report(self) -> dict[str, Any]:
        report = console("reconcile")
        self.assertIsInstance(report, dict, report)
        for name in ("paid_not_delivered", "delivered_not_paid", "pending_events"):
            self.assertIsInstance(report.get(name), list, report)
        ledger = report.get("ledger")
        self.assertIsInstance(ledger, dict, report)
        self.assertIs(type(ledger.get("balanced")), bool, ledger)
        for name in ("unbalanced_transactions", "totals_by_currency", "accounts", "missing_postings"):
            self.assertIsInstance(ledger.get(name), list, ledger)
        return report

    @staticmethod
    def report_order_ids(rows: list[dict[str, Any]]) -> set[str]:
        return {str(row.get("order_id", row.get("id"))) for row in rows}

    def assert_clean_ledger_report(self, report: dict[str, Any], order_id: str) -> None:
        ledger = report["ledger"]
        self.assertTrue(ledger["balanced"], ledger)
        self.assertEqual(ledger["unbalanced_transactions"], [], ledger)
        self.assertNotIn(order_id, self.report_order_ids(ledger["missing_postings"]))

    def drive(self, order_id: str, *, states: tuple[str, ...] = ("delivered",)) -> dict[str, Any]:
        deadline = time.monotonic() + POLL_TIMEOUT
        while time.monotonic() < deadline:
            body = self.order(order_id)
            if body["status"] in states:
                return body
            self.expedite([order_id])
            self.worker_once()
            time.sleep(0.02)
        self.fail(f"Order did not reach {states}: {self.diagnostic(order_id)}")

    def assert_paid(self, order_id: str) -> None:
        body = self.order(order_id)
        self.assertIsNotNone(body["paid_at"], body)
        self.assertEqual(
            self.count("ledger_transactions", f"order_id={quote(order_id)} AND kind='payment'"), 1
        )
        self.assertLessEqual(self.order_count("delivery_jobs", order_id), 1)

    def assert_delivered_once(self, order_id: str, provider: str | None = None) -> dict[str, Any]:
        body = self.order(order_id)
        self.assertEqual(body["status"], "delivered", self.diagnostic(order_id))
        self.assertIsNotNone(body["paid_at"])
        self.assertIsInstance(body["delivery"], dict)
        self.assertTrue(body["delivery"]["code"])
        if provider:
            self.assertEqual(body["delivery"]["provider"], provider)
        self.assertEqual(self.order_count("deliveries", order_id), 1)
        self.assertEqual(self.order_count("delivery_jobs", order_id), 0)
        self.assertEqual(
            self.count("supplier.requests", f"order_id={quote(order_id)} AND state='issued'"), 1,
            self.diagnostic(order_id),
        )
        self.assertEqual(
            self.count("delivery_attempts", f"order_id={quote(order_id)} AND state='succeeded'"), 1
        )
        transactions = sql(
            "SELECT t.kind,t.currency,count(e.id) AS entries,coalesce(sum(e.amount_minor),0) AS balance "
            "FROM ledger_transactions t LEFT JOIN ledger_entries e ON e.transaction_id=t.id "
            f"WHERE t.order_id={quote(order_id)} GROUP BY t.id,t.kind,t.currency ORDER BY t.kind"
        )
        self.assertEqual([row["kind"] for row in transactions], ["delivery", "payment"])
        for row in transactions:
            self.assertEqual(int(row["entries"]), 2, transactions)
            self.assertEqual(int(row["balance"]), 0, transactions)
            self.assertEqual(row["currency"], "RUB")
        postings = sql(
            "SELECT t.kind,e.account,e.amount_minor FROM ledger_entries e "
            "JOIN ledger_transactions t ON t.id=e.transaction_id "
            f"WHERE t.order_id={quote(order_id)}"
        )
        self.assertEqual(
            {(row["kind"], row["account"]): int(row["amount_minor"]) for row in postings},
            {
                ("payment", "cash"): int(body["amount_minor"]),
                ("payment", "customer_liability"): -int(body["amount_minor"]),
                ("delivery", "customer_liability"): int(body["amount_minor"]),
                ("delivery", "revenue"): -int(body["amount_minor"]),
            },
        )
        self.assertEqual(Decimal(str(body["amount"])), Decimal(body["amount_minor"]) / 100)
        return body

    def test_01_fifty_parallel_replays_create_one_payment_and_delivery(self) -> None:
        order_id = self.create(self.product())["order_id"]
        event = self.event(order_id)
        results = parallel([lambda: http("POST", "/webhook/payment", event) for _ in range(50)])
        self.assertTrue(all(status == 200 for status, _ in results), results)
        self.assertEqual(self.order_count("payment_events", order_id), 1)
        self.assert_paid(order_id)
        self.assertEqual(self.order_count("delivery_jobs", order_id), 1)
        self.drive(order_id)
        first = self.assert_delivered_once(order_id)
        self.assertEqual(http("POST", "/webhook/payment", event)[0], 200)
        self.worker_once()
        self.assertEqual(self.assert_delivered_once(order_id)["delivery"], first["delivery"])

    def test_02_fifty_distinct_parallel_paid_events_are_one_financial_effect(self) -> None:
        order_id = self.create(self.product())["order_id"]
        events = [self.event(order_id) for _ in range(50)]
        results = parallel([
            lambda event=event: http("POST", "/webhooks/payment", event) for event in events
        ])
        self.assertTrue(all(status == 200 for status, _ in results), results)
        self.assertEqual(self.order_count("payment_events", order_id), 50)
        self.assert_paid(order_id)
        self.drive(order_id)
        self.assert_delivered_once(order_id)

    def test_03_conflicting_event_id_cannot_change_original_payment(self) -> None:
        order_id = self.create(self.product())["order_id"]
        event = self.pay(order_id)
        before = sql(f"SELECT payload_hash,amount_minor,status FROM payment_events WHERE event_id={quote(event['event_id'])}")
        normalized_replay = {
            **event, "amount": "19.99", "created_at": "2026-09-08T15:00:00.123456+05:00",
            "gateway_metadata": {"irrelevant": "changed"},
        }
        self.assertEqual(http("POST", "/webhook/payment", normalized_replay)[0], 200)
        for changed in ({"amount": 20}, {"status": "failed"}, {"order_id": self.new_id("other")}):
            with self.subTest(changed=changed):
                status, body = http("POST", "/webhook/payment", {**event, **changed})
                self.assertEqual(status, 409, body)
        self.assertEqual(
            sql(f"SELECT payload_hash,amount_minor,status FROM payment_events WHERE event_id={quote(event['event_id'])}"), before
        )
        self.assertEqual(self.order_count("payment_events", order_id), 1)
        self.drive(order_id)
        self.assert_delivered_once(order_id)

    def test_04_early_event_is_durable_and_replayed_on_order_creation(self) -> None:
        sku = self.product()
        order_id = self.new_id("early")
        event = self.pay(order_id)
        pending = sql(f"SELECT state FROM payment_events WHERE event_id={quote(event['event_id'])}")
        self.assertEqual(pending, [{"state": "pending"}])
        report = self.report()
        self.assertIn(event["event_id"], {row["event_id"] for row in report["pending_events"]})
        self.assertEqual(self.order_count("delivery_jobs", order_id), 0)
        self.assertEqual(self.order_count("ledger_transactions", order_id), 0)
        self.create(sku, order_id)
        self.assert_paid(order_id)
        self.assertNotEqual(sql(f"SELECT state FROM payment_events WHERE event_id={quote(event['event_id'])}")[0]["state"], "pending")
        self.drive(order_id)
        self.assert_delivered_once(order_id)
        report = self.report()
        self.assertNotIn(event["event_id"], {row["event_id"] for row in report["pending_events"]})
        self.assert_clean_ledger_report(report, order_id)

    def test_05_payment_and_creation_race_has_no_stranded_inbox(self) -> None:
        sku = self.product(stock_a=12, stock_b=0)
        order_ids = [self.new_id("race") for _ in range(12)]
        self.orders.extend(order_ids)
        calls = []
        for order_id in order_ids:
            event = self.event(order_id)
            calls.append(lambda oid=order_id: http("POST", "/orders", {"sku": sku, "order_id": oid}))
            calls.append(lambda evt=event: http("POST", "/webhook/payment", evt))
        results = parallel(calls)
        self.assertTrue(all(status in (200, 201) for status, _ in results), results)
        for order_id in order_ids:
            self.assert_paid(order_id)
            self.assertEqual(self.count("payment_events", f"order_id={quote(order_id)} AND state='pending'"), 0)
        for order_id in order_ids:
            self.drive(order_id)
            self.assert_delivered_once(order_id)

    def test_06_paid_is_monotonic_despite_out_of_order_failed_events(self) -> None:
        sku = self.product()
        order_id = self.create(sku)["order_id"]
        self.pay(order_id, status="failed", created_at="2026-09-08T12:00:00Z")
        self.assertIsNone(self.order(order_id)["paid_at"])
        self.pay(order_id, created_at="2026-09-08T11:00:00Z")
        self.assert_paid(order_id)
        self.pay(order_id, status="failed", created_at="2026-09-08T13:00:00Z")
        self.assert_paid(order_id)
        self.drive(order_id)
        first = self.assert_delivered_once(order_id)
        self.pay(order_id, status="failed", created_at="2026-09-08T09:00:00Z")
        self.assertEqual(self.assert_delivered_once(order_id)["delivery"], first["delivery"])

    def test_07_money_or_currency_mismatch_is_audited_without_payment(self) -> None:
        order_id = self.create(self.product())["order_id"]
        for changes in ({"amount": 19.98}, {"amount": 20}, {"currency": "USD"}):
            event = self.pay(order_id, **changes)
            row = sql(f"SELECT state,reason FROM payment_events WHERE event_id={quote(event['event_id'])}")[0]
            self.assertEqual(row["state"], "rejected", row)
            self.assertTrue(row["reason"])
        self.assertIsNone(self.order(order_id)["paid_at"])
        self.assertEqual(self.order_count("ledger_transactions", order_id), 0)
        self.assertEqual(self.order_count("delivery_jobs", order_id), 0)
        self.worker_once()
        self.assertEqual(self.order_count("supplier.requests", order_id), 0)
        self.pay(order_id)
        self.drive(order_id)
        self.assert_delivered_once(order_id)

    def test_08_timeout_after_issue_reuses_request_and_never_falls_back(self) -> None:
        self.mode("timeout_after_issue", "A")
        order_id = self.create(self.product())["order_id"]
        self.pay(order_id)
        self.worker_once()
        first = self.attempts(order_id)
        self.assertEqual(len(first), 1, self.diagnostic(order_id))
        self.assertIn(first[0]["state"], ("ambiguous", "in_flight"), first)
        self.assertEqual(first[0]["provider"], "A")
        self.assertEqual(self.count("supplier.requests", f"order_id={quote(order_id)} AND state='issued'"), 1)
        self.assertEqual(self.count("supplier.calls", f"order_id={quote(order_id)} AND provider='B'"), 0)
        self.drive(order_id)
        body = self.assert_delivered_once(order_id, "A")
        self.assertEqual(body["delivery"]["request_id"], first[0]["request_id"])
        self.assertEqual(len(self.attempts(order_id)), 1)
        self.assertGreaterEqual(self.order_count("supplier.calls", order_id), 2)
        self.assertEqual(self.count("supplier.calls", f"order_id={quote(order_id)} AND provider='B'"), 0)

    def test_09_timeout_before_issue_stays_with_same_provider_and_request(self) -> None:
        self.mode("timeout_before_issue", "A")
        order_id = self.create(self.product())["order_id"]
        self.pay(order_id)
        self.worker_once()
        first = self.attempts(order_id)
        self.assertEqual(len(first), 1, self.diagnostic(order_id))
        self.assertIn(first[0]["state"], ("ambiguous", "in_flight"), first)
        self.assertEqual(self.count("supplier.calls", f"order_id={quote(order_id)} AND provider='B'"), 0)
        self.drive(order_id)
        body = self.assert_delivered_once(order_id, "A")
        self.assertEqual(body["delivery"]["request_id"], first[0]["request_id"])
        self.assertEqual(len(self.attempts(order_id)), 1)
        self.assertEqual(self.count("supplier.calls", f"order_id={quote(order_id)} AND provider='B'"), 0)

    def test_10_definitive_unavailability_allows_fallback_and_rejection_is_immutable(self) -> None:
        self.mode("unavailable", "A")
        sku = self.product()
        order_id = self.create(sku)["order_id"]
        self.pay(order_id)
        self.drive(order_id)
        self.assert_delivered_once(order_id, "B")
        rejected = sql(
            "SELECT request_id,state,reason FROM supplier.requests "
            f"WHERE order_id={quote(order_id)} AND provider='A'"
        )
        self.assertEqual(len(rejected), 1, rejected)
        self.assertEqual(rejected[0]["state"], "rejected")
        self.assertEqual(rejected[0]["reason"], "unavailable")
        self.mode("normal", "A")
        status, body = http("POST", "/issue", {
            "order_id": order_id, "sku": sku, "request_id": rejected[0]["request_id"]
        }, base=SUPPLIER_URLS["A"])
        self.assertGreaterEqual(status, 400, body)
        self.assertEqual(body.get("definitive"), True, body)
        self.assertEqual(body.get("status"), "error", body)
        self.assertEqual(body.get("reason"), "unavailable", body)
        self.assert_delivered_once(order_id, "B")

    def test_11_both_unavailable_remain_recoverable(self) -> None:
        self.mode("unavailable")
        order_id = self.create(self.product())["order_id"]
        self.pay(order_id)
        self.drive(order_id, states=("delivery_failed",))
        self.assertEqual(self.order_count("deliveries", order_id), 0)
        self.assertEqual(self.order_count("delivery_jobs", order_id), 1)
        rejected = {row["request_id"] for row in self.attempts(order_id) if row["state"] == "rejected"}
        self.assertEqual(len(rejected), 2, self.diagnostic(order_id))
        self.mode("normal")
        console("recover")
        self.drive(order_id)
        delivered = self.assert_delivered_once(order_id)
        self.assertNotIn(delivered["delivery"]["request_id"], rejected)

    def test_12_empty_inventory_recovers_after_restock(self) -> None:
        sku = self.product(stock_a=0, stock_b=0)
        order_id = self.create(sku)["order_id"]
        self.pay(order_id)
        self.drive(order_id, states=("out_of_stock",))
        self.assertEqual(self.order_count("deliveries", order_id), 0)
        self.assertEqual(self.order_count("delivery_jobs", order_id), 1)
        old = {row["request_id"] for row in self.attempts(order_id)}
        self.assertEqual(len(old), 2)
        self.restock(sku, "B", 1)
        console("recover")
        self.drive(order_id)
        body = self.assert_delivered_once(order_id, "B")
        self.assertNotIn(body["delivery"]["request_id"], old)

    def test_13_concurrent_workers_commit_only_one_delivery(self) -> None:
        order_id = self.create(self.product())["order_id"]
        self.pay(order_id)
        parallel([self.worker_once for _ in range(10)])
        self.drive(order_id)
        self.assert_delivered_once(order_id)
        self.assertEqual(len(self.attempts(order_id)), 1)
        self.assertEqual(self.order_count("supplier.calls", order_id), 1)

    def test_14_many_orders_and_workers_never_share_keys(self) -> None:
        sku = self.product(stock_a=6, stock_b=6)
        order_ids = [self.create(sku)["order_id"] for _ in range(10)]
        events = [self.event(order_id) for order_id in order_ids]
        results = parallel([lambda event=event: http("POST", "/webhook/payment", event) for event in events])
        self.assertTrue(all(status == 200 for status, _ in results), results)
        deadline = time.monotonic() + POLL_TIMEOUT
        while time.monotonic() < deadline:
            if all(self.order(order_id)["status"] == "delivered" for order_id in order_ids):
                break
            self.expedite(order_ids)
            parallel([self.worker_once for _ in range(6)])
        for order_id in order_ids:
            self.assert_delivered_once(order_id)
        ids = ",".join(quote(order_id) for order_id in order_ids)
        stats = sql(
            "SELECT count(*) AS n,count(DISTINCT code) AS codes,count(DISTINCT request_id) AS requests "
            f"FROM deliveries WHERE order_id IN ({ids})"
        )[0]
        self.assertEqual({key: int(value) for key, value in stats.items()}, {"n": 10, "codes": 10, "requests": 10})

    def test_15_crash_after_supplier_commit_recovers_original_request(self) -> None:
        sku = self.product()
        order_id = self.create(sku)["order_id"]
        self.pay(order_id)
        request_id = self.new_id("crash")
        sql(
            "INSERT INTO delivery_attempts(order_id,provider,request_id,state,calls) "
            f"VALUES({quote(order_id)},'A',{quote(request_id)},'in_flight',1) RETURNING id"
        )
        status, issued = http("POST", "/issue", {
            "order_id": order_id, "sku": sku, "request_id": request_id
        }, base=SUPPLIER_URLS["A"])
        self.assertEqual(status, 200, issued)
        self.assertEqual(self.order_count("deliveries", order_id), 0)
        console("recover")
        self.assertEqual(self.attempts(order_id)[0]["request_id"], request_id)
        self.drive(order_id)
        body = self.assert_delivered_once(order_id, "A")
        self.assertEqual(body["delivery"]["request_id"], request_id)
        self.assertEqual(body["delivery"]["code"], issued["code"])
        self.assertEqual(len(self.attempts(order_id)), 1)

    def test_16_crash_before_http_reuses_persisted_in_flight_attempt(self) -> None:
        order_id = self.create(self.product())["order_id"]
        self.pay(order_id)
        request_id = self.new_id("before-http")
        sql(
            "INSERT INTO delivery_attempts(order_id,provider,request_id,state,calls) "
            f"VALUES({quote(order_id)},'A',{quote(request_id)},'in_flight',0) RETURNING id"
        )
        self.assertEqual(self.order_count("supplier.requests", order_id), 0)
        self.drive(order_id)
        body = self.assert_delivered_once(order_id, "A")
        self.assertEqual(body["delivery"]["request_id"], request_id)
        self.assertEqual(len(self.attempts(order_id)), 1)

    def test_17_recovery_restores_missing_job_idempotently(self) -> None:
        order_id = self.create(self.product())["order_id"]
        self.pay(order_id)
        # This targeted deletion is the deliberate crash/corruption fixture.
        # It never deletes business data or fixtures belonging to other runs.
        sql(f"DELETE FROM delivery_jobs WHERE order_id={quote(order_id)} RETURNING order_id")
        self.assertEqual(self.order_count("delivery_jobs", order_id), 0)
        before = self.report()
        self.assertIn(order_id, self.report_order_ids(before["paid_not_delivered"]))
        self.assertNotIn(order_id, self.report_order_ids(before["delivered_not_paid"]))
        self.assert_clean_ledger_report(before, order_id)
        first_recovery = console("recover")
        second_recovery = console("recover")
        self.assertIs(type(first_recovery.get("enqueued")), int, first_recovery)
        self.assertGreaterEqual(first_recovery["enqueued"], 1)
        self.assertIs(type(second_recovery.get("enqueued")), int, second_recovery)
        self.assertEqual(second_recovery["enqueued"], 0)
        self.assertEqual(self.order_count("delivery_jobs", order_id), 1)
        self.assert_paid(order_id)
        self.drive(order_id)
        self.assert_delivered_once(order_id)
        after = self.report()
        self.assertNotIn(order_id, self.report_order_ids(after["paid_not_delivered"]))
        self.assertNotIn(order_id, self.report_order_ids(after["delivered_not_paid"]))
        self.assert_clean_ledger_report(after, order_id)

    def test_18_concurrent_order_replay_and_price_snapshot(self) -> None:
        sku = self.product()
        order_id = self.new_id("order")
        self.orders.append(order_id)
        results = parallel([lambda: http("POST", "/orders", {"sku": sku, "order_id": order_id}) for _ in range(20)])
        self.assertEqual(sum(status == 201 for status, _ in results), 1, results)
        self.assertEqual(sum(status == 200 for status, _ in results), 19, results)
        self.assertTrue(all(body["order_id"] == order_id for _, body in results))
        other_sku = self.product()
        status, body = http("POST", "/orders", {"sku": other_sku, "order_id": order_id})
        self.assertEqual(status, 409, body)
        sql(f"UPDATE products SET price_minor=2999 WHERE sku={quote(sku)} RETURNING sku")
        self.assertEqual(int(self.order(order_id)["amount_minor"]), 1999)
        replay_status, replay = http("POST", "/orders", {"sku": sku, "order_id": order_id})
        self.assertEqual(replay_status, 200, replay)
        self.assertEqual(int(replay["amount_minor"]), 1999)
        self.pay(order_id)
        self.drive(order_id)
        self.assert_delivered_once(order_id)
        later = self.create(sku)
        self.assertEqual(int(later["amount_minor"]), 2999)

    def test_19_http_rejects_invalid_input_without_server_errors(self) -> None:
        sku = self.product()
        order_id = self.create(sku)["order_id"]
        valid = self.event(order_id)
        cases = [
            ("/orders", b"{", "application/json", 400),
            ("/orders", b"[]", "application/json", 422),
            ("/orders", b"null", "application/json", 422),
            ("/orders", b"{}", "text/plain", 415),
            ("/orders", b'{"sku":"a","sku":"b"}', "application/json", 422),
            ("/orders", json.dumps({"sku": sku, "order_id": "bad/id"}).encode(), "application/json", 422),
            ("/orders", json.dumps({"sku": sku, "order_id": "x" * 129}).encode(), "application/json", 422),
            ("/orders", json.dumps({"sku": self.new_id("missing")}).encode(), "application/json", 404),
            ("/orders", b'{"sku":' + b'"' + b'x' * 65536 + b'"}', "application/json", 413),
        ]
        for path, raw, content_type, expected in cases:
            with self.subTest(path=path, raw=raw[:80], expected=expected):
                status, body = http("POST", path, raw=raw, content_type=content_type)
                self.assertEqual(status, expected, body)
        invalid = [
            {"amount": True}, {"amount": -1}, {"amount": 19.999},
            {"amount": "19.990000000000000001"}, {"amount": []},
            {"status": ["paid"]}, {"status": "refunded"}, {"currency": "RUBX"},
            {"created_at": "2026-02-30T10:00:00Z"}, {"created_at": "2026-09-08T10:00:00"},
            {"order_id": {}}, {"event_id": ""},
        ]
        for changes in invalid:
            with self.subTest(changes=changes):
                status, body = http("POST", "/webhook/payment", {**valid, **changes})
                self.assertEqual(status, 422, body)
        precise_token = json.dumps(valid, separators=(",", ":")).replace(
            '"amount":19.99', '"amount":19.990000000000000001'
        ).encode()
        self.assertEqual(http("POST", "/webhook/payment", raw=precise_token)[0], 422)
        missing = {key: value for key, value in valid.items() if key != "created_at"}
        self.assertEqual(http("POST", "/webhook/payment", missing)[0], 422)
        self.assertEqual(http("GET", "/orders/" + self.new_id("missing"))[0], 404)
        self.assertIn(http("GET", "/webhook/payment")[0], (404, 405))
        self.assertIsNone(self.order(order_id)["paid_at"])
        self.assertEqual(self.order_count("ledger_transactions", order_id), 0)

    def test_20_database_rejects_unbalanced_ledger_transaction(self) -> None:
        order_id = self.create(self.product())["order_id"]
        statement = (
            "WITH paid AS (UPDATE orders SET status='paid',paid_at=now() "
            f"WHERE id={quote(order_id)} RETURNING id), "
            "tx AS (INSERT INTO ledger_transactions(order_id,kind,currency) "
            "SELECT id,'payment','RUB' FROM paid RETURNING id) "
            "INSERT INTO ledger_entries(transaction_id,account,amount_minor) "
            "SELECT id,'cash',1999 FROM tx RETURNING id"
        )
        result = console("test:sql", statement, check=False)
        self.assertNotEqual(result.returncode, 0, "Database accepted an unbalanced ledger transaction")
        self.assertIn("23514", result.stderr, "Expected check violation, not a broken trigger or SQL error")
        self.assertEqual(self.order_count("ledger_transactions", order_id), 0)
        self.assertIsNone(self.order(order_id)["paid_at"], "Invalid posting did not roll back its whole transaction")
        self.pay(order_id)
        self.drive(order_id)
        self.assert_delivered_once(order_id)

    def test_21_supplier_request_cannot_be_reused_for_another_order(self) -> None:
        sku = self.product()
        first = self.create(sku)["order_id"]
        second = self.create(sku)["order_id"]
        request_id = self.new_id("supplier-id")
        status, issued = http("POST", "/issue", {"sku": sku, "order_id": first, "request_id": request_id}, base=SUPPLIER_URLS["A"])
        self.assertEqual(status, 200, issued)
        status, body = http("POST", "/issue", {"sku": sku, "order_id": second, "request_id": request_id}, base=SUPPLIER_URLS["A"])
        self.assertEqual(status, 409, body)
        self.assertNotIn("code", body)
        self.assertEqual(self.order_count("supplier.requests", second), 0)
        # Attach the legitimate issued outcome to its paid order and recover it.
        self.pay(first)
        sql(
            "INSERT INTO delivery_attempts(order_id,provider,request_id,state,calls) "
            f"VALUES({quote(first)},'A',{quote(request_id)},'in_flight',1) RETURNING id"
        )
        self.drive(first)
        self.assert_delivered_once(first, "A")
        self.pay(second)
        self.drive(second)
        self.assert_delivered_once(second, "A")

    def test_22_delayed_request_cannot_issue_after_definitive_rejection(self) -> None:
        self.mode("timeout_before_issue", "A", delay_ms=1500)
        sku = self.product()
        order_id = self.create(sku)["order_id"]
        request_id = self.new_id("fenced")
        payload = {"sku": sku, "order_id": order_id, "request_id": request_id}
        with self.assertRaises((TimeoutError, urllib.error.URLError)):
            http("POST", "/issue", payload, base=SUPPLIER_URLS["A"], timeout=0.15)
        self.assertEqual(self.order_count("supplier.calls", order_id), 1)
        self.mode("unavailable", "A")
        status, body = http("POST", "/issue", payload, base=SUPPLIER_URLS["A"])
        self.assertEqual(status, 503, body)
        self.assertEqual(body.get("definitive"), True, body)
        # Let the original request resume after the immutable rejection commits.
        time.sleep(1.6)
        self.assertEqual(self.count("supplier.requests", f"order_id={quote(order_id)} AND state='issued'"), 0)
        self.assertEqual(self.count("supplier.requests", f"order_id={quote(order_id)} AND state='rejected'"), 1)
        self.mode("normal", "A")
        status, body = http("POST", "/issue", payload, base=SUPPLIER_URLS["A"])
        self.assertEqual(status, 503, body)
        self.assertEqual(body.get("reason"), "unavailable", body)
        # A new safe cycle can still issue for this order using another id.
        self.pay(order_id)
        self.drive(order_id)
        self.assert_delivered_once(order_id, "A")

    def test_23_untrusted_supplier_responses_pin_request_without_fallback(self) -> None:
        replies = [
            (200, b"not-json"),
            (500, b'{"status":"error","reason":"temporary_failure"}'),
            (503, b'{"status":"error","provider":"A","request_id":"wrong-id","reason":"unavailable","definitive":true}'),
        ]

        class BrokenSupplier(BaseHTTPRequestHandler):
            def do_POST(inner_self: Any) -> None:
                inner_self.rfile.read(int(inner_self.headers.get("Content-Length", "0")))
                status, body = replies.pop(0)
                inner_self.send_response(status)
                inner_self.send_header("Content-Type", "application/json")
                inner_self.send_header("Content-Length", str(len(body)))
                inner_self.end_headers()
                inner_self.wfile.write(body)

            def log_message(inner_self: Any, *_args: Any) -> None:
                pass

        server = ThreadingHTTPServer(("127.0.0.1", 0), BrokenSupplier)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        order_id = self.create(self.product())["order_id"]
        self.pay(order_id)
        request_id = None
        try:
            for _ in range(3):
                self.expedite([order_id])
                console("worker:once", env={"SUPPLIER_A_URL": f"http://127.0.0.1:{server.server_port}"})
                attempts = self.attempts(order_id)
                self.assertEqual(len(attempts), 1, self.diagnostic(order_id))
                self.assertEqual(attempts[0]["state"], "ambiguous", attempts)
                request_id = request_id or attempts[0]["request_id"]
                self.assertEqual(attempts[0]["request_id"], request_id)
                self.assertEqual(self.order_count("supplier.requests", order_id), 0)
                self.assertEqual(self.count("supplier.calls", f"order_id={quote(order_id)} AND provider='B'"), 0)
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=2)
        self.assertEqual(replies, [])
        self.drive(order_id)
        delivered = self.assert_delivered_once(order_id, "A")
        self.assertEqual(delivered["delivery"]["request_id"], request_id)

    def test_24_reconciliation_finds_delivery_without_payment(self) -> None:
        sku = self.product()
        order_id = self.create(sku)["order_id"]
        request_id = self.new_id("unpaid-delivery")
        status, issued = http("POST", "/issue", {
            "sku": sku, "order_id": order_id, "request_id": request_id
        }, base=SUPPLIER_URLS["A"])
        self.assertEqual(status, 200, issued)
        # Deliberately model a legacy/import anomaly, without changing an existing
        # payment or ledger row. Later real payment and normal recovery repair it.
        sql(
            "INSERT INTO delivery_attempts(order_id,provider,request_id,state,calls) "
            f"VALUES({quote(order_id)},'A',{quote(request_id)},'in_flight',1) RETURNING id"
        )
        sql(
            "INSERT INTO deliveries(order_id,provider,request_id,code) VALUES "
            f"({quote(order_id)},'A',{quote(request_id)},{quote(issued['code'])}) RETURNING order_id"
        )
        before = self.report()
        self.assertIn(order_id, self.report_order_ids(before["delivered_not_paid"]))
        self.assertNotIn(order_id, self.report_order_ids(before["paid_not_delivered"]))
        self.assertEqual(self.order_count("ledger_transactions", order_id), 0)
        self.pay(order_id)
        self.drive(order_id)
        self.assert_delivered_once(order_id, "A")
        after = self.report()
        self.assertNotIn(order_id, self.report_order_ids(after["delivered_not_paid"]))
        self.assertNotIn(order_id, self.report_order_ids(after["paid_not_delivered"]))
        self.assert_clean_ledger_report(after, order_id)

    def test_25_catalog_cursor_filters_and_invalid_queries(self) -> None:
        # Far above seeded catalog prices; the cursor isolates this run without
        # hiding or deleting any pre-existing products. Tied prices test the SKU
        # tiebreaker rather than merely increasing-price pagination.
        base_price = 900000000000000000 + int(uuid.uuid4().hex[:8], 16) * 100
        visible = []
        fixtures = []
        for index in range(9):
            sku = self.new_id("catalog").upper()
            visible.append(sku)
            fixtures.append((sku, "subscription", base_price + index // 3, 1, True))
        fixtures.extend([
            (self.new_id("hidden").upper(), "subscription", base_price, 0, True),
            (self.new_id("inactive").upper(), "subscription", base_price, 1, False),
            (self.new_id("other-type").upper(), "key", base_price, 1, True),
        ])
        values = ",".join(
            f"({quote(sku)},'Catalog acceptance fixture',{quote(kind)},{price},'RUB',{stock},{'true' if active else 'false'})"
            for sku, kind, price, stock, active in fixtures
        )
        sql(
            "INSERT INTO products(sku,name,type,price_minor,currency,available_stock,active) "
            f"VALUES {values} RETURNING sku"
        )
        cursor = {"after_price": base_price - 1, "after_sku": self.namespace.upper()}
        seen = []
        for _ in range(4):
            expected = sql(
                "SELECT sku,price_minor FROM products WHERE active AND available_stock>0 "
                "AND type='subscription' "
                f"AND (price_minor,sku)>({int(cursor['after_price'])},{quote(cursor['after_sku'])}) "
                "ORDER BY price_minor,sku LIMIT 4"
            )
            status, page = http("GET", "/catalog?" + urlencode({"type": "subscription", "limit": 3, **cursor}))
            self.assertEqual(status, 200, page)
            self.assertIsInstance(page.get("items"), list, page)
            items = page["items"]
            self.assertEqual([item["sku"] for item in items], [row["sku"] for row in expected[:3]])
            for item in items:
                self.assertEqual(item["type"], "subscription")
                self.assertGreater(int(item["available_stock"]), 0)
                self.assertEqual(Decimal(item["price"]), Decimal(item["price_minor"]) / 100)
                self.assertNotIn(item["sku"], seen, "A cursor repeated the previous page boundary")
                seen.append(item["sku"])
            next_cursor = page.get("next_cursor")
            if len(expected) <= 3:
                self.assertIsNone(next_cursor, page)
                break
            self.assertEqual(next_cursor, {
                "after_price": int(items[-1]["price_minor"]), "after_sku": items[-1]["sku"]
            })
            cursor = next_cursor
        self.assertTrue(set(visible).issubset(seen), {"missing": set(visible) - set(seen)})

        # The unfiltered view still excludes inactive and empty-stock products.
        query = {"limit": 15, "after_price": base_price - 1, "after_sku": self.namespace.upper()}
        status, unfiltered = http("GET", "/catalog?" + urlencode(query))
        self.assertEqual(status, 200, unfiltered)
        expected = sql(
            "SELECT sku FROM products WHERE active AND available_stock>0 "
            f"AND (price_minor,sku)>({base_price - 1},{quote(self.namespace.upper())}) "
            "ORDER BY price_minor,sku LIMIT 15"
        )
        self.assertEqual([item["sku"] for item in unfiltered["items"]], [row["sku"] for row in expected])
        for query_string in (
            "type=unknown", "limit=0", "limit=101", "limit=-1", "limit=1.5", "limit=abc",
            "after_price=1", "after_sku=X", "after_price=-1&after_sku=X",
            "after_price=1.25&after_sku=X", "after_price=1&after_sku=bad%2Fid", "limit%5B%5D=1",
        ):
            with self.subTest(query=query_string):
                status, body = http("GET", "/catalog?" + query_string)
                self.assertEqual(status, 422, body)

    def test_26_backoff_defers_then_allows_retry_without_expedite(self) -> None:
        self.mode("unavailable")
        order_id = self.create(self.product())["order_id"]
        self.pay(order_id)
        timing = {"BACKOFF_BASE_MS": "1200", "BACKOFF_MAX_MS": "1200"}
        console("worker:once", env=timing)
        self.assertEqual(self.order(order_id)["status"], "delivery_failed")
        self.assertEqual(self.order_count("supplier.calls", order_id), 2)
        first_attempts = self.attempts(order_id)
        self.assertEqual(len(first_attempts), 2)
        job = sql(
            "SELECT attempts,extract(epoch FROM available_at-now())*1000 AS remaining_ms "
            f"FROM delivery_jobs WHERE order_id={quote(order_id)}"
        )[0]
        self.assertGreater(float(job["remaining_ms"]), 0, job)
        self.assertLessEqual(float(job["remaining_ms"]), 1200, job)

        console("worker:once", env=timing)
        self.assertEqual(self.order_count("supplier.calls", order_id), 2)
        self.assertEqual(self.attempts(order_id), first_attempts)
        self.assertEqual(
            int(sql(f"SELECT attempts FROM delivery_jobs WHERE order_id={quote(order_id)}")[0]["attempts"]),
            int(job["attempts"]),
        )
        self.mode("normal")
        remaining = sql(
            "SELECT greatest(0,extract(epoch FROM available_at-now())) AS seconds "
            f"FROM delivery_jobs WHERE order_id={quote(order_id)}"
        )[0]
        time.sleep(float(remaining["seconds"]) + 0.075)
        deadline = time.monotonic() + POLL_TIMEOUT
        while time.monotonic() < deadline:
            console("worker:once", env=timing)
            if self.order(order_id)["status"] == "delivered":
                break
            time.sleep(0.05)
        self.assert_delivered_once(order_id, "A")
        self.assertEqual(self.order_count("supplier.calls", order_id), 3)
        self.assertNotIn(
            self.order(order_id)["delivery"]["request_id"],
            {attempt["request_id"] for attempt in first_attempts},
        )

    def test_27_killed_worker_recovers_after_supplier_committed(self) -> None:
        self.mode("timeout_after_issue", "A", delay_ms=3000)
        order_id = self.create(self.product())["order_id"]
        self.pay(order_id)
        process = subprocess.Popen(
            [PHP_BIN, str(ROOT / "bin" / "console"), "worker:once"],
            cwd=ROOT,
            env={**os.environ, "SUPPLIER_TIMEOUT_MS": "5000"},
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            text=True,
        )
        try:
            deadline = time.monotonic() + 2
            while time.monotonic() < deadline:
                if self.count("supplier.requests", f"order_id={quote(order_id)} AND state='issued'") == 1:
                    break
                time.sleep(0.02)
            else:
                self.fail("Supplier did not commit before crash injection: " + self.diagnostic(order_id))
            self.assertIsNone(process.poll(), "Worker completed before the intended crash window")
            process.kill()
            process.communicate(timeout=5)
            self.assertLess(process.returncode, 0, "Worker was not terminated by a signal")
            attempt = self.attempts(order_id)[0]
            self.assertEqual(attempt["state"], "in_flight")
            self.assertEqual(self.order_count("deliveries", order_id), 0)
            self.mode("normal")
            self.drive(order_id)
            delivery = self.assert_delivered_once(order_id, "A")["delivery"]
            self.assertEqual(delivery["request_id"], attempt["request_id"])
            self.assertEqual(self.order_count("supplier.calls", order_id), 2)
        finally:
            if process.poll() is None:
                process.kill()
                process.communicate(timeout=5)

    @unittest.skipUnless(os.environ.get("RUN_RANDOM_TESTS") == "1", "optional stochastic scenario; use RUN_RANDOM_TESTS=1")
    def test_90_random_faults_recover_and_preserve_single_delivery(self) -> None:
        self.mode("random", error_rate=0.3, timeout_rate=0.3, delay_ms=900)
        sku = self.product(stock_a=8, stock_b=8)
        order_ids = [self.create(sku)["order_id"] for _ in range(8)]
        for order_id in order_ids:
            self.pay(order_id)
        for _ in range(4):
            self.expedite(order_ids)
            parallel([self.worker_once for _ in range(4)])
        # Randomness affects the path, never the pass criterion or deadline.
        self.mode("normal")
        console("recover")
        for order_id in order_ids:
            self.drive(order_id)
            self.assert_delivered_once(order_id)


if __name__ == "__main__":
    unittest.main(verbosity=2)
