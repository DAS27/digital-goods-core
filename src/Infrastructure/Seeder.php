<?php

declare(strict_types=1);

namespace App\Infrastructure;

use PDO;

final class Seeder
{
    public static function run(PDO $db): array
    {
        return Database::transaction($db, static function (PDO $db): array {
            $db->exec("SELECT pg_advisory_xact_lock(hashtextextended('schema:seed',0))");
            $products = json_decode(file_get_contents(dirname(__DIR__, 2) . '/database/catalog.json'), true, 32, JSON_THROW_ON_ERROR);
            $keys = json_decode(file_get_contents(dirname(__DIR__, 2) . '/database/keys.json'), true, 32, JSON_THROW_ON_ERROR);
            $insert = $db->prepare("INSERT INTO products(sku,name,type,price_minor,currency) VALUES(:sku,:name,:type,:price_minor,'RUB') ON CONFLICT(sku) DO NOTHING");
            foreach ($products as $product) {
                $insert->execute($product);
            }
            $db->exec("INSERT INTO supplier.settings(provider) VALUES('A'),('B') ON CONFLICT(provider) DO NOTHING");
            $insert = $db->prepare('INSERT INTO supplier.stock(provider,sku,code) VALUES(:provider,:sku,:code) ON CONFLICT(code) DO NOTHING');
            foreach ($keys as $index => $code) {
                $insert->execute(['provider' => intdiv($index, count($products)) % 2 === 0 ? 'A' : 'B', 'sku' => $products[$index % count($products)]['sku'], 'code' => $code]);
            }
            // Demo-only inventory import; runtime delivery never queries supplier tables.
            $sync = $db->prepare('UPDATE products p SET available_stock=(SELECT count(*) FROM supplier.stock s WHERE s.sku=p.sku AND s.request_id IS NULL) WHERE p.sku=:sku');
            foreach ($products as $product) {
                $sync->execute(['sku' => $product['sku']]);
            }

            return ['products' => count($products), 'seed_keys' => count($keys)];
        });
    }
}
