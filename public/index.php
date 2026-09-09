<?php

declare(strict_types=1);

use App\Domain\CatalogService;
use App\Domain\OrderService;
use App\Domain\PaymentService;
use App\Http\ApiException;
use App\Http\JsonRequest;
use App\Http\Response;
use App\Infrastructure\Database;
use App\Infrastructure\Log;

require dirname(__DIR__) . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? (rtrim($path, '/') ?: '/') : '/';

try {
    $allowedMethod = match (true) {
        $path === '/orders', $path === '/webhook/payment', $path === '/webhooks/payment' => 'POST',
        $path === '/catalog', $path === '/health', preg_match('~\A/orders/[^/]+\z~D', $path) === 1 => 'GET',
        default => null,
    };
    if ($allowedMethod === null) {
        throw new ApiException(404, 'Route not found');
    }
    if ($method !== $allowedMethod) {
        header('Allow: ' . $allowedMethod);
        throw new ApiException(405, 'Method not allowed');
    }

    if ($path === '/health') {
        try {
            Database::connect()->query('SELECT 1')->fetchColumn();
        } catch (Throwable) {
            throw new ApiException(503, 'Database unavailable');
        }
        Response::json(['status' => 'ok']);
    } elseif ($path === '/orders') {
        $input = JsonRequest::read();
        $order = (new OrderService(Database::connect()))->create($input);
        $status = $order['_created'] ? 201 : 200;
        unset($order['_created']);
        header('Location: /orders/' . rawurlencode($order['order_id']));
        Response::json($order, $status);
    } elseif ($path === '/webhook/payment' || $path === '/webhooks/payment') {
        $input = JsonRequest::read();
        Response::json((new PaymentService(Database::connect()))->receive($input));
    } elseif ($path === '/catalog') {
        Response::json((new CatalogService(Database::connect()))->list($_GET));
    } else {
        Response::json((new OrderService(Database::connect()))->get(rawurldecode(substr($path, strlen('/orders/')))));
    }
} catch (ApiException $error) {
    Response::json(['error' => ['message' => $error->getMessage()]], $error->status);
} catch (Throwable $error) {
    // Do not log response bodies, SQL messages or delivered secret codes.
    Log::write('http.internal_error', ['exception' => get_class($error), 'method' => $method]);
    Response::json(['error' => ['message' => 'Internal server error']], 500);
}
