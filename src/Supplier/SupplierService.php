<?php

declare(strict_types=1);

namespace App\Supplier;

use App\Infrastructure\Database;
use PDO;
use RuntimeException;

/** The supplier owns its stock and its durable idempotency records. */
final class SupplierService
{
    public function __construct(private readonly PDO $db, private readonly string $provider)
    {
        if (!in_array($provider, ['A', 'B'], true)) {
            throw new RuntimeException('Invalid supplier name.');
        }
    }

    /** @return array{status: int, body: array<string, mixed>} */
    public function issue(string $requestId, string $orderId, string $sku): array
    {
        $outcome = Database::transaction($this->db, function (PDO $db) use ($requestId, $orderId, $sku): array {
            $this->lock($db, $requestId, $orderId);
            $query = $db->prepare('INSERT INTO supplier.calls (provider, request_id, order_id) VALUES (:provider, :request_id, :order_id)');
            $query->execute(['provider' => $this->provider, 'request_id' => $requestId, 'order_id' => $orderId]);

            $existing = $this->existing($db, $requestId, $orderId, $sku);
            if ($existing !== null) {
                return $existing;
            }

            $query = $db->prepare('SELECT mode, error_rate, timeout_rate, delay_ms FROM supplier.settings WHERE provider = :provider');
            $query->execute(['provider' => $this->provider]);
            $settings = $query->fetch(PDO::FETCH_ASSOC);
            if ($settings === false) {
                throw new RuntimeException('Supplier settings missing.');
            }

            $mode = $this->selectMode($settings);
            $delayMs = max(0, min(60000, (int) $settings['delay_ms']));
            if ($mode === 'timeout_before_issue') {
                // A timed out caller cannot tell whether this delayed request will issue.
                // No row locks or transaction remain open while the delay runs.
                return ['deferred' => true, 'delay_ms' => $delayMs];
            }

            $result = $this->persistOutcome($db, $requestId, $orderId, $sku, $mode);
            if ($mode === 'timeout_after_issue' && $result['status'] === 200) {
                $result['delay_ms'] = $delayMs;
            }

            return $result;
        });

        if (isset($outcome['delay_ms']) && $outcome['delay_ms'] > 0) {
            usleep($outcome['delay_ms'] * 1000);
        }

        if (isset($outcome['deferred'])) {
            $outcome = Database::transaction($this->db, function (PDO $db) use ($requestId, $orderId, $sku): array {
                $this->lock($db, $requestId, $orderId);
                // Another invocation may have committed a permanent rejection while this
                // request slept. Replaying it fences the late request from issuing a key.
                return $this->existing($db, $requestId, $orderId, $sku)
                    ?? $this->persistOutcome($db, $requestId, $orderId, $sku, 'normal');
            });
        }

        return ['status' => $outcome['status'], 'body' => $outcome['body']];
    }

    private function lock(PDO $db, string $requestId, string $orderId): void
    {
        // Every supplier writer takes these locks in the same order. They also work
        // before request/stock rows exist, unlike a SELECT FOR UPDATE on missing rows.
        $query = $db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:lock_key, 0))');
        $query->execute(['lock_key' => 'supplier-request:' . $this->provider . ':' . $requestId]);
        $query->execute(['lock_key' => 'supplier-order:' . $this->provider . ':' . $orderId]);
    }

    /** @return array{status: int, body: array<string, mixed>}|null */
    private function existing(PDO $db, string $requestId, string $orderId, string $sku): ?array
    {
        $query = $db->prepare('SELECT order_id, sku, state, code, reason FROM supplier.requests WHERE provider = :provider AND request_id = :request_id');
        $query->execute(['provider' => $this->provider, 'request_id' => $requestId]);
        $request = $query->fetch(PDO::FETCH_ASSOC);
        if ($request !== false) {
            if ($request['order_id'] !== $orderId || $request['sku'] !== $sku) {
                return $this->error($requestId, 'idempotency_conflict', 409, false);
            }
            if ($request['state'] === 'issued') {
                return $this->success($requestId, $request['code']);
            }

            return $this->error($requestId, $request['reason'], $request['reason'] === 'out_of_stock' ? 409 : 503, true);
        }

        $query = $db->prepare("SELECT request_id FROM supplier.requests WHERE provider = :provider AND order_id = :order_id AND state = 'issued'");
        $query->execute(['provider' => $this->provider, 'order_id' => $orderId]);
        if ($query->fetchColumn() !== false) {
            // A new request id must never purchase another key for an already issued
            // order. This is not proof of non-issuance, so fallback is not authorized.
            return $this->error($requestId, 'order_already_issued', 409, false);
        }

        return null;
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function persistOutcome(PDO $db, string $requestId, string $orderId, string $sku, string $mode): array
    {
        if (in_array($mode, ['unavailable', 'out_of_stock'], true)) {
            return $this->reject($db, $requestId, $orderId, $sku, $mode);
        }

        $query = $db->prepare('SELECT id, code FROM supplier.stock WHERE provider = :provider AND sku = :sku AND request_id IS NULL ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED');
        $query->execute(['provider' => $this->provider, 'sku' => $sku]);
        $stock = $query->fetch(PDO::FETCH_ASSOC);
        if ($stock === false) {
            return $this->reject($db, $requestId, $orderId, $sku, 'out_of_stock');
        }

        $query = $db->prepare('UPDATE supplier.stock SET request_id = :request_id WHERE id = :id');
        $query->execute(['request_id' => $requestId, 'id' => $stock['id']]);
        $query = $db->prepare("INSERT INTO supplier.requests (provider, request_id, order_id, sku, state, code) VALUES (:provider, :request_id, :order_id, :sku, 'issued', :code)");
        $query->execute(['provider' => $this->provider, 'request_id' => $requestId, 'order_id' => $orderId, 'sku' => $sku, 'code' => $stock['code']]);

        return $this->success($requestId, $stock['code']);
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function reject(PDO $db, string $requestId, string $orderId, string $sku, string $reason): array
    {
        $query = $db->prepare("INSERT INTO supplier.requests (provider, request_id, order_id, sku, state, reason) VALUES (:provider, :request_id, :order_id, :sku, 'rejected', :reason)");
        $query->execute(['provider' => $this->provider, 'request_id' => $requestId, 'order_id' => $orderId, 'sku' => $sku, 'reason' => $reason]);

        return $this->error($requestId, $reason, $reason === 'out_of_stock' ? 409 : 503, true);
    }

    /** @param array<string, mixed> $settings */
    private function selectMode(array $settings): string
    {
        $mode = $settings['mode'];
        if ($mode !== 'random') {
            return $mode;
        }

        $roll = random_int(0, 999999) / 1000000;
        $errorRate = max(0.0, min(1.0, (float) $settings['error_rate']));
        $timeoutRate = max(0.0, min(1.0 - $errorRate, (float) $settings['timeout_rate']));
        if ($roll < $errorRate) {
            return 'unavailable';
        }

        return $roll < $errorRate + $timeoutRate ? 'timeout_after_issue' : 'normal';
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function success(string $requestId, string $code): array
    {
        return ['status' => 200, 'body' => ['status' => 'ok', 'provider' => $this->provider, 'request_id' => $requestId, 'code' => $code]];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function error(string $requestId, string $reason, int $status, bool $definitive): array
    {
        return ['status' => $status, 'body' => ['status' => 'error', 'reason' => $reason, 'provider' => $this->provider, 'request_id' => $requestId, 'definitive' => $definitive]];
    }
}
