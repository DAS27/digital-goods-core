<?php

declare(strict_types=1);

namespace App\Domain;

use App\Infrastructure\Database;
use App\Infrastructure\Log;
use PDO;

final class ReconciliationService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function report(): array
    {
        return Database::transaction($this->db, static function (PDO $db): array {
            $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $paid = $db->query("SELECT o.id AS order_id,o.status,o.paid_at,j.attempts,j.last_error,j.available_at,
                    floor(extract(epoch FROM now()-o.updated_at))::bigint AS age_seconds
                FROM orders o LEFT JOIN delivery_jobs j ON j.order_id=o.id
                WHERE o.paid_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM deliveries d WHERE d.order_id=o.id)
                ORDER BY o.created_at,o.id")->fetchAll();
            $unpaid = $db->query("SELECT o.id AS order_id,o.status,d.provider,d.request_id FROM deliveries d
                JOIN orders o ON o.id=d.order_id WHERE o.paid_at IS NULL")->fetchAll();
            $pending = $db->query("SELECT event_id,order_id,created_at FROM payment_events WHERE state='pending' ORDER BY created_at,event_id")->fetchAll();
            $unbalanced = $db->query("SELECT t.id,t.order_id,t.kind,t.currency,count(e.id) AS entries,coalesce(sum(e.amount_minor),0) AS balance
                FROM ledger_transactions t LEFT JOIN ledger_entries e ON e.transaction_id=t.id
                GROUP BY t.id HAVING count(e.id) <> 2 OR coalesce(sum(e.amount_minor),0) <> 0")->fetchAll();
            $totals = $db->query('SELECT t.currency,sum(e.amount_minor) AS balance FROM ledger_entries e JOIN ledger_transactions t ON t.id=e.transaction_id GROUP BY t.currency ORDER BY t.currency')->fetchAll();
            $accounts = $db->query('SELECT t.currency,e.account,sum(e.amount_minor) AS amount_minor FROM ledger_entries e JOIN ledger_transactions t ON t.id=e.transaction_id GROUP BY t.currency,e.account ORDER BY t.currency,e.account')->fetchAll();
            $missing = $db->query("SELECT o.id AS order_id,'payment' AS kind FROM orders o WHERE o.paid_at IS NOT NULL
                    AND NOT EXISTS (SELECT 1 FROM ledger_transactions t WHERE t.order_id=o.id AND t.kind='payment')
                UNION ALL SELECT d.order_id,'delivery' FROM deliveries d
                    WHERE NOT EXISTS (SELECT 1 FROM ledger_transactions t WHERE t.order_id=d.order_id AND t.kind='delivery')")->fetchAll();
            $nonzero = array_filter($totals, static fn (array $row): bool => (string) $row['balance'] !== '0');

            return [
                'checked_at' => gmdate('Y-m-d\TH:i:s\Z'), 'paid_not_delivered' => $paid,
                'delivered_not_paid' => $unpaid, 'pending_events' => $pending,
                'ledger' => ['balanced' => $unbalanced === [] && $nonzero === [] && $missing === [],
                    'unbalanced_transactions' => $unbalanced, 'totals_by_currency' => $totals,
                    'accounts' => $accounts, 'missing_postings' => $missing],
            ];
        });
    }

    /** Manual recovery expedites jobs; periodic repair preserves existing backoff. */
    public function recover(bool $expedite = true): array
    {
        $result = Database::transaction($this->db, static function (PDO $db) use ($expedite): array {
            $condition = $expedite ? '' : ' AND NOT EXISTS (SELECT 1 FROM delivery_jobs j WHERE j.order_id=o.id)';
            // Same lock ordering as payment and delivery: order -> job. No network.
            $orders = $db->query("SELECT o.id FROM orders o WHERE o.paid_at IS NOT NULL AND o.status <> 'delivered'
                AND NOT EXISTS (SELECT 1 FROM deliveries d WHERE d.order_id=o.id)" . $condition
                . ' ORDER BY o.updated_at,o.id LIMIT 500 FOR UPDATE OF o SKIP LOCKED')->fetchAll(PDO::FETCH_COLUMN);
            $insert = $db->prepare('INSERT INTO delivery_jobs(order_id) VALUES(:id) ON CONFLICT(order_id) DO NOTHING RETURNING order_id');
            $update = $db->prepare('UPDATE delivery_jobs SET available_at=LEAST(available_at,now()) WHERE order_id=:id');
            $enqueued = 0;
            foreach ($orders as $id) {
                $insert->execute(['id' => $id]);
                $enqueued += $insert->fetchColumn() !== false ? 1 : 0;
                if ($expedite) {
                    $update->execute(['id' => $id]);
                }
            }

            return ['enqueued' => $enqueued, 'examined' => count($orders), 'expedited' => $expedite];
        });
        if ($result['enqueued'] > 0) {
            Log::write('recovery.enqueued', ['enqueued' => $result['enqueued']]);
        }

        return $result;
    }
}
