<?php

declare(strict_types=1);

namespace App\Delivery;

use App\Infrastructure\Config;
use JsonException;

/** A supplier response is evidence only when it matches the durable request id. */
final class SupplierClient
{
    /** @return array{kind: string, reason?: string, code?: string} */
    public function issue(string $provider, string $requestId, string $orderId, string $sku): array
    {
        $defaultUrl = $provider === 'A' ? 'http://supplier-a:8081' : 'http://supplier-b:8082';
        $url = rtrim(Config::get('SUPPLIER_' . $provider . '_URL', $defaultUrl), '/') . '/issue';
        $timeoutMs = max(1, min(60000, (int) Config::get('SUPPLIER_TIMEOUT_MS', '300')));
        $curl = curl_init($url);
        if ($curl === false) {
            return ['kind' => 'ambiguous', 'reason' => 'http_initialization_failed'];
        }

        $body = '';
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['request_id' => $requestId, 'order_id' => $orderId, 'sku' => $sku], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 1000),
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 65536) {
                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        try {
            $completed = curl_exec($curl);
            $error = curl_errno($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        } finally {
            curl_close($curl);
        }

        // Conservatively pin even connection failures. A previous process may have
        // sent the same request before crashing, so a current connection refusal
        // does not establish that this durable attempt has never issued a key.
        if ($completed === false || $error !== CURLE_OK) {
            return ['kind' => 'ambiguous', 'reason' => $error === CURLE_OPERATION_TIMEDOUT ? 'transport_timeout' : 'transport_error_' . $error];
        }

        try {
            $response = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['kind' => 'ambiguous', 'reason' => 'invalid_json'];
        }

        if (!is_array($response)
            || ($response['request_id'] ?? null) !== $requestId
            || ($response['provider'] ?? null) !== $provider) {
            return ['kind' => 'ambiguous', 'reason' => 'unmatched_response'];
        }

        if ($status === 200 && ($response['status'] ?? null) === 'ok' && is_string($response['code'] ?? null)
            && $response['code'] !== '' && strlen($response['code']) <= 4096
            && preg_match('/[\x00-\x1F\x7F]/', $response['code']) === 0
            && !isset($response['reason']) && !isset($response['error'])) {
            return ['kind' => 'success', 'code' => $response['code']];
        }

        $reason = $response['reason'] ?? null;
        if (($response['status'] ?? null) === 'error' && ($response['definitive'] ?? null) === true
            && !isset($response['code'])
            && (($status === 409 && $reason === 'out_of_stock') || ($status === 503 && $reason === 'unavailable'))) {
            return ['kind' => 'rejected', 'reason' => $reason];
        }

        return ['kind' => 'ambiguous', 'reason' => 'unexpected_http_' . $status];
    }
}
