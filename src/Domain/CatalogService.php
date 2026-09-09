<?php

declare(strict_types=1);

namespace App\Domain;

use App\Http\ApiException;
use App\Http\Input;
use App\Http\Money;
use PDO;

final class CatalogService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function list(array $query): array
    {
        $limit = isset($query['limit']) ? $this->integer($query['limit'], 'limit', 100) : 50;
        $where = ['active', 'available_stock > 0'];
        $params = [];
        if (isset($query['type'])) {
            if (!is_string($query['type']) || !in_array($query['type'], ['key', 'topup', 'subscription', 'giftcard'], true)) {
                throw new ApiException(422, 'Invalid product type');
            }
            $where[] = 'type = :type';
            $params['type'] = $query['type'];
        }
        if (isset($query['after_price']) !== isset($query['after_sku'])) {
            throw new ApiException(422, 'after_price and after_sku must be used together');
        }
        if (isset($query['after_price'])) {
            $where[] = '(price_minor,sku) > (:after_price,:after_sku)';
            $params['after_price'] = $this->integer($query['after_price'], 'after_price', PHP_INT_MAX);
            $params['after_sku'] = Input::identifier($query['after_sku'], 'after_sku');
        }
        $statement = $this->db->prepare('SELECT sku,name,type,price_minor,currency,available_stock FROM products WHERE '
            . implode(' AND ', $where) . ' ORDER BY price_minor,sku LIMIT :row_limit');
        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue('row_limit', $limit + 1, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $items = array_map(static fn (array $row): array => [
            'sku' => $row['sku'], 'name' => $row['name'], 'type' => $row['type'],
            'price' => Money::fromMinor((int) $row['price_minor']), 'price_minor' => (int) $row['price_minor'],
            'currency' => $row['currency'], 'available_stock' => (int) $row['available_stock'],
        ], $rows);
        $last = $items === [] ? null : $items[array_key_last($items)];

        return ['items' => $items, 'next_cursor' => $hasMore ? ['after_price' => $last['price_minor'], 'after_sku' => $last['sku']] : null];
    }

    private function integer(mixed $value, string $field, int $max): int
    {
        if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1
            || strlen($value) > strlen((string) $max)
            || (strlen($value) === strlen((string) $max) && strcmp($value, (string) $max) > 0)) {
            throw new ApiException(422, $field . ' must be a positive integer <= ' . $max);
        }

        return (int) $value;
    }
}
