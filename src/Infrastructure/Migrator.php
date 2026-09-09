<?php

declare(strict_types=1);

namespace App\Infrastructure;

use PDO;
use RuntimeException;

final class Migrator
{
    public static function run(PDO $db): array
    {
        return Database::transaction($db, static function (PDO $db): array {
            $db->exec("SELECT pg_advisory_xact_lock(hashtextextended('schema:migrate',0))");
            $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version text PRIMARY KEY, checksum text NOT NULL, applied_at timestamptz NOT NULL DEFAULT now())');
            $applied = [];
            foreach (glob(dirname(__DIR__, 2) . '/database/*.sql') as $file) {
                $name = basename($file);
                $checksum = hash_file('sha256', $file);
                $statement = $db->prepare('SELECT checksum FROM schema_migrations WHERE version=:version');
                $statement->execute(['version' => $name]);
                $existing = $statement->fetchColumn();
                if ($existing !== false) {
                    if ($existing !== $checksum) {
                        throw new RuntimeException('Applied migration changed: ' . $name);
                    }
                    continue;
                }
                $db->exec(file_get_contents($file));
                $statement = $db->prepare('INSERT INTO schema_migrations(version,checksum) VALUES(:version,:checksum)');
                $statement->execute(['version' => $name, 'checksum' => $checksum]);
                $applied[] = $name;
            }

            return ['applied' => $applied];
        });
    }
}
