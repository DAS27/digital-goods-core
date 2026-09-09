<?php

declare(strict_types=1);

namespace App\Infrastructure;

use LogicException;
use PDO;
use Throwable;

final class Database
{
    public static function connect(): PDO
    {
        $db = new PDO(
            Config::get('DATABASE_DSN', 'pgsql:host=127.0.0.1;port=5432;dbname=goods'),
            Config::get('DATABASE_USER', 'goods'),
            Config::get('DATABASE_PASSWORD', 'goods'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_PERSISTENT => false],
        );
        $db->exec("SET TIME ZONE 'UTC'; SET statement_timeout = '15s'; SET lock_timeout = '10s'; SET idle_in_transaction_session_timeout = '20s'");

        return $db;
    }

    /** SQL-only callback: external side effects must never be retried by this helper. */
    public static function transaction(PDO $db, callable $callback): mixed
    {
        if ($db->inTransaction()) {
            throw new LogicException('Nested transactions are not supported');
        }
        $db->beginTransaction();
        try {
            $result = $callback($db);
            $db->commit();

            return $result;
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }
}
