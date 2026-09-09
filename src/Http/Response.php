<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    public static function json(array $body, int $status = 200): void
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo $json . "\n";
    }
}
