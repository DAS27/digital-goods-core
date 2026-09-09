<?php

declare(strict_types=1);

namespace App\Delivery;

use App\Infrastructure\Config;
use App\Infrastructure\Database;
use App\Infrastructure\Ledger;
use App\Infrastructure\Log;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Uses a dedicated session connection: transaction-pooling proxies are unsupported.
 * Database transactions are short; the order advisory lock spans the HTTP call.
 */
final class DeliveryWorker
{
    private readonly SupplierClient $supplier;

    public function __construct(private readonly PDO $db)
    {
        $this->supplier = new SupplierClient();
    }

    /** Process at most one due order, including a proven-safe fallback to B. */
    public function tick(): bool
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Worker requires a connection outside a transaction.');
        }

        $query = $this->db->query("SELECT j.order_id FROM delivery_jobs j JOIN orders o ON o.id = j.order_id
            WHERE j.available_at <= now() AND o.paid_at IS NOT NULL AND o.status <> 'delivered'
            ORDER BY j.available_at, j.created_at, j.order_id LIMIT 32");
        $candidates = $query->fetchAll(PDO::FETCH_COLUMN);

        foreach ($candidates as $orderId) {
            if (!$this->tryLock($orderId)) {
                continue;
            }

            try {
                $attempt = $this->prepareAttempt($orderId);
                if ($attempt === null) {
                    continue;
                }

                while (true) {
                    Log::write('delivery.request', [
                        'order_id' => $orderId,
                        'provider' => $attempt['provider'],
                        'request_id' => $attempt['request_id'],
                        'call' => (int) $attempt['calls'],
                    ]);
                    $result = $this->supplier->issue($attempt['provider'], $attempt['request_id'], $orderId, $attempt['sku']);

                    if ($result['kind'] === 'success') {
                        $this->complete($attempt, $result['code']);

                        return true;
                    }
                    if ($result['kind'] === 'ambiguous') {
                        $this->retryAmbiguous($attempt, $result['reason']);

                        return true;
                    }

                    $fallback = $this->reject($attempt, $result['reason']);
                    if (!$fallback) {
                        return true;
                    }
                    // A rejection and the next durable attempt were committed together.
                    // A crash here will resume B, without starting a new A request.
                    $attempt = $this->prepareAttempt($orderId);
                    if ($attempt === null) {
                        return true;
                    }
                }
            } catch (Throwable $exception) {
                Log::write('delivery.worker_failed', ['order_id' => $orderId, 'exception' => get_class($exception)]);
                // An in_flight attempt survives any failure, including a local commit
                // failure after the supplier issued. Recovery repeats its same id.
                throw $exception;
            } finally {
                $this->unlock($orderId);
            }
        }

        return false;
    }

    private function tryLock(string $orderId): bool
    {
        $query = $this->db->prepare("SELECT pg_try_advisory_lock(hashtextextended('delivery:' || :order_id, 0))");
        $query->execute(['order_id' => $orderId]);
        $locked = $query->fetchColumn();

        return $locked === true || $locked === 't' || $locked === 1 || $locked === '1';
    }

    private function unlock(string $orderId): void
    {
        $query = $this->db->prepare("SELECT pg_advisory_unlock(hashtextextended('delivery:' || :order_id, 0))");
        $query->execute(['order_id' => $orderId]);
    }

    /** @return array<string, mixed>|null */
    private function prepareAttempt(string $orderId): ?array
    {
        return Database::transaction($this->db, function (PDO $db) use ($orderId): ?array {
            // Recheck the due time after obtaining the session lock. Another worker
            // may have scheduled backoff after our initial candidate query.
            // Keep the same row-lock order as payment/recovery: order, then job.
            // A joined FOR UPDATE would leave the lock order to the query planner.
            $order = $this->lockOrder($db, $orderId);
            if ($order['paid_at'] === null || $order['status'] === 'delivered') {
                return null;
            }
            $query = $db->prepare('SELECT order_id FROM delivery_jobs WHERE order_id = :order_id AND available_at <= now() FOR UPDATE');
            $query->execute(['order_id' => $orderId]);
            if ($query->fetchColumn() === false) {
                return null;
            }

            $query = $db->prepare("SELECT * FROM delivery_attempts WHERE order_id = :order_id AND state IN ('pending', 'in_flight', 'ambiguous') FOR UPDATE");
            $query->execute(['order_id' => $orderId]);
            $attempt = $query->fetch(PDO::FETCH_ASSOC);
            if ($attempt === false) {
                $attempt = $this->newAttempt($db, $orderId, 'A');
            }

            $query = $db->prepare("UPDATE delivery_attempts SET state = 'in_flight', calls = calls + 1, updated_at = now() WHERE id = :id RETURNING *");
            $query->execute(['id' => $attempt['id']]);
            $attempt = $query->fetch(PDO::FETCH_ASSOC);
            $query = $db->prepare('UPDATE delivery_jobs SET attempts = attempts + 1 WHERE order_id = :order_id');
            $query->execute(['order_id' => $orderId]);
            $query = $db->prepare("UPDATE orders SET status = 'delivering', updated_at = now() WHERE id = :order_id");
            $query->execute(['order_id' => $orderId]);
            $attempt['sku'] = $order['sku'];

            return $attempt;
        });
    }

    /** @return array<string, mixed> */
    private function newAttempt(PDO $db, string $orderId, string $provider): array
    {
        $query = $db->prepare("INSERT INTO delivery_attempts (order_id, provider, request_id, state)
            VALUES (:order_id, :provider, :request_id, 'pending') RETURNING *");
        $query->execute(['order_id' => $orderId, 'provider' => $provider, 'request_id' => bin2hex(random_bytes(16))]);

        return $query->fetch(PDO::FETCH_ASSOC);
    }

    /** @param array<string, mixed> $attempt */
    private function complete(array $attempt, string $code): void
    {
        Database::transaction($this->db, function (PDO $db) use ($attempt, $code): void {
            $order = $this->lockOrder($db, $attempt['order_id']);
            if ($order['paid_at'] === null) {
                throw new RuntimeException('Cannot deliver an unpaid order.');
            }

            $query = $db->prepare('SELECT provider, request_id, code FROM deliveries WHERE order_id = :order_id');
            $query->execute(['order_id' => $attempt['order_id']]);
            $existing = $query->fetch(PDO::FETCH_ASSOC);
            if ($existing !== false) {
                if ($existing['provider'] !== $attempt['provider'] || $existing['request_id'] !== $attempt['request_id'] || $existing['code'] !== $code) {
                    throw new RuntimeException('Delivery conflicts with the recorded result.');
                }
            } else {
                $query = $db->prepare('INSERT INTO deliveries (order_id, provider, request_id, code) VALUES (:order_id, :provider, :request_id, :code)');
                $query->execute(['order_id' => $attempt['order_id'], 'provider' => $attempt['provider'], 'request_id' => $attempt['request_id'], 'code' => $code]);
                // Catalogue stock is an availability hint, never a reservation. Only
                // a fresh delivery changes it; replaying a completion cannot subtract twice.
                $query = $db->prepare('UPDATE products SET available_stock = GREATEST(available_stock - 1, 0) WHERE sku = :sku');
                $query->execute(['sku' => $order['sku']]);
            }

            $query = $db->prepare("UPDATE delivery_attempts SET state = 'succeeded', reason = NULL, updated_at = now() WHERE id = :id");
            $query->execute(['id' => $attempt['id']]);
            $query = $db->prepare("UPDATE orders SET status = 'delivered', updated_at = now() WHERE id = :order_id");
            $query->execute(['order_id' => $attempt['order_id']]);
            Ledger::delivery($db, $order);
            $query = $db->prepare('DELETE FROM delivery_jobs WHERE order_id = :order_id');
            $query->execute(['order_id' => $attempt['order_id']]);
        });

        Log::write('delivery.completed', ['order_id' => $attempt['order_id'], 'provider' => $attempt['provider'], 'request_id' => $attempt['request_id']]);
    }

    /** @param array<string, mixed> $attempt */
    private function retryAmbiguous(array $attempt, string $reason): void
    {
        Database::transaction($this->db, function (PDO $db) use ($attempt, $reason): void {
            $this->lockOrder($db, $attempt['order_id']);
            $query = $db->prepare("UPDATE delivery_attempts SET state = 'ambiguous', reason = :reason, updated_at = now() WHERE id = :id");
            $query->execute(['reason' => $reason, 'id' => $attempt['id']]);
            $query = $db->prepare("UPDATE orders SET status = 'delivery_failed', updated_at = now() WHERE id = :order_id AND status <> 'delivered'");
            $query->execute(['order_id' => $attempt['order_id']]);
            $this->backoff($db, $attempt['order_id'], $reason);
        });

        Log::write('delivery.ambiguous', ['order_id' => $attempt['order_id'], 'provider' => $attempt['provider'], 'request_id' => $attempt['request_id'], 'reason' => $reason]);
    }

    /** A return value of true means B was durably scheduled for the same cycle. */
    private function reject(array $attempt, string $reason): bool
    {
        $fallback = Database::transaction($this->db, function (PDO $db) use ($attempt, $reason): bool {
            $this->lockOrder($db, $attempt['order_id']);
            $query = $db->prepare("UPDATE delivery_attempts SET state = 'rejected', reason = :reason, updated_at = now() WHERE id = :id");
            $query->execute(['reason' => $reason, 'id' => $attempt['id']]);

            if ($attempt['provider'] === 'A') {
                $this->newAttempt($db, $attempt['order_id'], 'B');

                return true;
            }

            $query = $db->prepare("SELECT reason FROM delivery_attempts WHERE order_id = :order_id AND provider = 'A'
                AND state = 'rejected' AND id < :id ORDER BY id DESC LIMIT 1");
            $query->execute(['order_id' => $attempt['order_id'], 'id' => $attempt['id']]);
            $aReason = $query->fetchColumn();
            $status = $reason === 'out_of_stock' && $aReason === 'out_of_stock' ? 'out_of_stock' : 'delivery_failed';
            $query = $db->prepare("UPDATE orders SET status = :status, updated_at = now() WHERE id = :order_id AND status <> 'delivered'");
            $query->execute(['status' => $status, 'order_id' => $attempt['order_id']]);
            $this->backoff($db, $attempt['order_id'], $status === 'out_of_stock' ? 'both_out_of_stock' : 'suppliers_rejected');

            return false;
        });

        Log::write('delivery.rejected', ['order_id' => $attempt['order_id'], 'provider' => $attempt['provider'], 'request_id' => $attempt['request_id'], 'reason' => $reason, 'fallback' => $fallback]);

        return $fallback;
    }

    /** @return array<string, mixed> */
    private function lockOrder(PDO $db, string $orderId): array
    {
        $query = $db->prepare('SELECT * FROM orders WHERE id = :order_id FOR UPDATE');
        $query->execute(['order_id' => $orderId]);
        $order = $query->fetch(PDO::FETCH_ASSOC);
        if ($order === false) {
            throw new RuntimeException('Delivery order disappeared.');
        }

        return $order;
    }

    private function backoff(PDO $db, string $orderId, string $reason): void
    {
        $query = $db->prepare('SELECT attempts FROM delivery_jobs WHERE order_id = :order_id FOR UPDATE');
        $query->execute(['order_id' => $orderId]);
        $attempts = (int) $query->fetchColumn();
        $baseMs = max(1, min(60000, (int) Config::get('BACKOFF_BASE_MS', '200')));
        $maximumMs = max($baseMs, min(3600000, (int) Config::get('BACKOFF_MAX_MS', '30000')));
        $capMs = (int) min($maximumMs, $baseMs * (2 ** min(20, max(0, $attempts - 1))));
        // Equal jitter keeps a minimum quiet period and spreads concurrent retries.
        $delayMs = random_int(max(1, (int) ceil($capMs / 2)), $capMs);
        $query = $db->prepare("UPDATE delivery_jobs SET available_at = now() + CAST(:delay_ms AS integer) * INTERVAL '1 millisecond', last_error = :reason WHERE order_id = :order_id");
        $query->execute(['delay_ms' => $delayMs, 'reason' => $reason, 'order_id' => $orderId]);
    }
}
