<?php

declare(strict_types=1);

namespace App\Domain;

use App\Http\ApiException;
use App\Http\Input;
use App\Http\Money;
use App\Infrastructure\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class OrderService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function create(array $input): array
    {
        $sku = Input::identifier($input['sku'] ?? null, 'sku');
        $id = array_key_exists('order_id', $input)
            ? Input::identifier($input['order_id'], 'order_id')
            : 'ord_' . bin2hex(random_bytes(16));

        return Database::transaction($this->db, function (PDO $db) use ($id, $sku): array {
            // This lock must precede both order and inbox access. It also works
            // when the order does not exist, closing the early-webhook race.
            $lock = $db->prepare("SELECT pg_advisory_xact_lock(hashtextextended('payment:' || CAST(:id AS text), 0))");
            $lock->execute(['id' => $id]);

            $lookup = $db->prepare('SELECT id, sku FROM orders WHERE id = :id FOR UPDATE');
            $lookup->execute(['id' => $id]);
            $existing = $lookup->fetch(PDO::FETCH_ASSOC);
            if ($existing !== false) {
                if ($existing['sku'] !== $sku) {
                    throw new ApiException(409, 'order_id already belongs to a different SKU');
                }
                (new PaymentService($db))->replayPending($id);

                return $this->get($id) + ['_created' => false];
            }

            $productQuery = $db->prepare('SELECT sku, price_minor, currency FROM products WHERE sku = :sku AND active = true FOR SHARE');
            $productQuery->execute(['sku' => $sku]);
            $product = $productQuery->fetch(PDO::FETCH_ASSOC);
            if ($product === false) {
                throw new ApiException(404, 'Product not found');
            }

            // Always freeze the server-side catalog price in the order.
            $insert = $db->prepare("INSERT INTO orders (id, sku, amount_minor, currency, status) VALUES (:id, :sku, :amount_minor, :currency, 'created')");
            $insert->execute([
                'id' => $id,
                'sku' => $sku,
                'amount_minor' => $product['price_minor'],
                'currency' => $product['currency'],
            ]);
            (new PaymentService($db))->replayPending($id);

            return $this->get($id) + ['_created' => true];
        });
    }

    public function get(string $id): array
    {
        $id = Input::identifier($id, 'order_id');
        // One statement gives status and key from one committed snapshot.
        $query = $this->db->prepare(
            'SELECT o.id, o.sku, o.status, o.amount_minor, o.currency, o.paid_at, '
            . 'd.provider, d.request_id, d.code '
            . 'FROM orders o LEFT JOIN deliveries d ON d.order_id = o.id WHERE o.id = :id',
        );
        $query->execute(['id' => $id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiException(404, 'Order not found');
        }

        return [
            'order_id' => $row['id'],
            'sku' => $row['sku'],
            'status' => $row['status'],
            'amount' => Money::fromMinor((int) $row['amount_minor']),
            'amount_minor' => (int) $row['amount_minor'],
            'currency' => $row['currency'],
            'paid_at' => $row['paid_at'] === null ? null
                : (new DateTimeImmutable($row['paid_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'delivery' => $row['request_id'] === null ? null : [
                'provider' => $row['provider'],
                'request_id' => $row['request_id'],
                'code' => $row['code'],
            ],
        ];
    }
}
