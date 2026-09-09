<?php

declare(strict_types=1);

namespace App\Domain;

use App\Http\ApiException;
use App\Http\Input;
use App\Http\Money;
use App\Infrastructure\Database;
use App\Infrastructure\Ledger;
use App\Infrastructure\Log;
use PDO;
use LogicException;

final class PaymentService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function receive(array $input): array
    {
        $event = $this->normalize($input);

        $result = Database::transaction($this->db, function (PDO $db) use ($event): array {
            $this->lockOrder($event['order_id']);
            $insert = $db->prepare(
                'INSERT INTO payment_events (event_id, order_id, status, amount_minor, currency, occurred_at, payload_hash) '
                . 'VALUES (:event_id, :order_id, :status, :amount_minor, :currency, :occurred_at, :payload_hash) '
                . 'ON CONFLICT (event_id) DO NOTHING RETURNING event_id',
            );
            $insert->execute($event);

            if ($insert->fetchColumn() === false) {
                // A conflicting event for another order may hold a different
                // advisory lock. INSERT waits for that transaction to commit;
                // this fresh statement then sees its immutable inbox payload.
                $existingQuery = $db->prepare('SELECT payload_hash, state FROM payment_events WHERE event_id = :event_id');
                $existingQuery->execute(['event_id' => $event['event_id']]);
                $existing = $existingQuery->fetch(PDO::FETCH_ASSOC);
                if ($existing === false) {
                    throw new LogicException('Conflicting inbox row was not visible');
                }
                if (!hash_equals($existing['payload_hash'], $event['payload_hash'])) {
                    throw new ApiException(409, 'event_id already exists with a different payment payload');
                }

                return $this->result($event, 'duplicate', $existing['state']);
            }

            $outcome = $this->apply($event);

            return $this->result($event, $outcome, $outcome);
        });
        Log::write('payment.received', $result);

        return $result;
    }

    /** Called inside order creation, so order, replay, ledger and job commit together. */
    public function replayPending(string $orderId): void
    {
        if (!$this->db->inTransaction()) {
            throw new LogicException('Pending payment replay requires an existing transaction');
        }
        $this->lockOrder($orderId);
        $query = $this->db->prepare(
            "SELECT event_id, order_id, status, amount_minor, currency, occurred_at, payload_hash "
            . "FROM payment_events WHERE order_id = :order_id AND state = 'pending' "
            . 'ORDER BY occurred_at, event_id FOR UPDATE',
        );
        $query->execute(['order_id' => $orderId]);
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $event) {
            $this->apply($event);
        }
    }

    private function normalize(array $input): array
    {
        $status = $input['status'] ?? null;
        if ($status !== 'paid' && $status !== 'failed') {
            throw new ApiException(422, 'status must be paid or failed');
        }

        $event = [
            'event_id' => Input::identifier($input['event_id'] ?? null, 'event_id'),
            'order_id' => Input::identifier($input['order_id'] ?? null, 'order_id'),
            'status' => $status,
            'amount_minor' => Money::toMinor($input['amount'] ?? null),
            'currency' => Input::currency($input['currency'] ?? null),
            'occurred_at' => Input::timestamp($input['created_at'] ?? null),
        ];
        // Ignore unrelated gateway metadata, but bind the event ID to every
        // meaningful known field using normalized money and timestamp values.
        $event['payload_hash'] = hash('sha256', json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $event;
    }

    private function lockOrder(string $orderId): void
    {
        $lock = $this->db->prepare("SELECT pg_advisory_xact_lock(hashtextextended('payment:' || CAST(:id AS text), 0))");
        $lock->execute(['id' => $orderId]);
    }

    /** Caller owns the payment advisory lock and SQL transaction. */
    private function apply(array $event): string
    {
        $orderQuery = $this->db->prepare('SELECT id, sku, status, amount_minor, currency, paid_at FROM orders WHERE id = :id FOR UPDATE');
        $orderQuery->execute(['id' => $event['order_id']]);
        $order = $orderQuery->fetch(PDO::FETCH_ASSOC);
        if ($order === false) {
            // Keep a durable inbox row even when the order has not arrived yet.
            return 'pending';
        }

        if ((int) $event['amount_minor'] !== (int) $order['amount_minor'] || $event['currency'] !== $order['currency']) {
            $this->mark($event['event_id'], 'rejected', 'amount_or_currency_mismatch');

            return 'rejected';
        }

        // Successful payment is monotonic financial truth. Neither stale nor
        // newer failure notifications can revoke a payment or delivered key.
        if ($order['paid_at'] !== null) {
            $this->mark($event['event_id'], 'ignored', 'order_already_paid');

            return 'ignored';
        }

        if ($event['status'] === 'failed') {
            if ($order['status'] === 'payment_failed') {
                $this->mark($event['event_id'], 'ignored', 'order_already_payment_failed');

                return 'ignored';
            }
            $update = $this->db->prepare("UPDATE orders SET status = 'payment_failed', updated_at = now() WHERE id = :id");
            $update->execute(['id' => $order['id']]);
        } else {
            $update = $this->db->prepare("UPDATE orders SET status = 'paid', paid_at = :paid_at, updated_at = now() WHERE id = :id");
            $update->execute(['id' => $order['id'], 'paid_at' => $event['occurred_at']]);
            Ledger::payment($this->db, $order);
            $job = $this->db->prepare('INSERT INTO delivery_jobs (order_id) VALUES (:order_id) ON CONFLICT (order_id) DO NOTHING');
            $job->execute(['order_id' => $order['id']]);
        }

        $this->mark($event['event_id'], 'applied', null);

        return 'applied';
    }

    private function mark(string $eventId, string $state, ?string $reason): void
    {
        $update = $this->db->prepare('UPDATE payment_events SET state = :state, reason = :reason, processed_at = now() WHERE event_id = :event_id');
        $update->execute(['event_id' => $eventId, 'state' => $state, 'reason' => $reason]);
    }

    private function result(array $event, string $outcome, string $state): array
    {
        return [
            'event_id' => $event['event_id'],
            'order_id' => $event['order_id'],
            'outcome' => $outcome,
            'state' => $state,
        ];
    }
}
