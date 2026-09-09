<?php

declare(strict_types=1);

use App\Infrastructure\Config;
use App\Infrastructure\Database;
use App\Infrastructure\Log;
use App\Supplier\SupplierService;

require dirname(__DIR__) . '/bootstrap.php';

// A simulated timeout must not cancel an operation after the caller disconnects.
ignore_user_abort(true);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
};

try {
    $provider = Config::get('SUPPLIER_NAME');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'GET' && $path === '/health') {
        Database::connect()->query('SELECT 1');
        $respond(200, ['status' => 'ok', 'provider' => $provider]);
    }
    if ($method !== 'POST' || $path !== '/issue') {
        $respond(404, ['status' => 'error', 'reason' => 'not_found']);
    }

    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') {
        $respond(415, ['status' => 'error', 'reason' => 'json_required']);
    }
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if ($raw === false || strlen($raw) > 8192) {
        $respond(413, ['status' => 'error', 'reason' => 'request_too_large']);
    }
    try {
        $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        $respond(422, ['status' => 'error', 'reason' => 'invalid_json']);
    }
    if (!is_array($input)) {
        $respond(422, ['status' => 'error', 'reason' => 'invalid_request']);
    }
    foreach (['request_id', 'order_id', 'sku'] as $field) {
        if (!isset($input[$field]) || !is_string($input[$field]) || trim($input[$field]) === ''
            || strlen($input[$field]) > 200 || preg_match('/[\x00-\x1F\x7F]/', $input[$field])) {
            $respond(422, ['status' => 'error', 'reason' => 'invalid_' . $field]);
        }
    }

    $service = new SupplierService(Database::connect(), $provider);
    $result = $service->issue($input['request_id'], $input['order_id'], $input['sku']);
    $respond($result['status'], $result['body']);
} catch (Throwable $exception) {
    Log::write('supplier.failed', ['exception' => get_class($exception)]);
    $respond(500, ['status' => 'error', 'reason' => 'internal_error']);
}
