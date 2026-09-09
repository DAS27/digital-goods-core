<?php

declare(strict_types=1);

namespace App\Infrastructure;

use LogicException;
use PDO;

final class Ledger
{
    public static function payment(PDO $db, array $order): void
    {
        self::post($db, $order, 'payment', 'cash', 'customer_liability');
    }

    public static function delivery(PDO $db, array $order): void
    {
        self::post($db, $order, 'delivery', 'customer_liability', 'revenue');
    }

    private static function post(PDO $db, array $order, string $kind, string $positive, string $negative): void
    {
        if (!$db->inTransaction()) {
            throw new LogicException('Ledger postings require a surrounding transaction');
        }
        $statement = $db->prepare('INSERT INTO ledger_transactions (order_id,kind,currency) VALUES (:order_id,:kind,:currency) ON CONFLICT (order_id,kind) DO NOTHING RETURNING id');
        $statement->execute(['order_id' => $order['id'], 'kind' => $kind, 'currency' => $order['currency']]);
        $id = $statement->fetchColumn();
        if ($id === false) {
            return;
        }
        $statement = $db->prepare('INSERT INTO ledger_entries (transaction_id,account,amount_minor) VALUES (:transaction_id,:account,:amount)');
        $statement->execute(['transaction_id' => $id, 'account' => $positive, 'amount' => (int) $order['amount_minor']]);
        $statement->execute(['transaction_id' => $id, 'account' => $negative, 'amount' => -(int) $order['amount_minor']]);
    }
}
