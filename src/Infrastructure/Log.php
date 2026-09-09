<?php

declare(strict_types=1);

namespace App\Infrastructure;

final class Log
{
    public static function write(string $event, array $context = []): void
    {
        // Allowlisted fields: never accidentally log a supplier key or raw payload.
        $context = array_intersect_key($context, array_flip([
            'order_id', 'event_id', 'provider', 'request_id', 'call', 'reason', 'fallback',
            'exception', 'method', 'state', 'outcome', 'enqueued', 'elapsed_ms',
        ]));
        file_put_contents('php://stderr', json_encode([
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'), 'event' => $event,
        ] + $context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    }
}
