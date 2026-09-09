<?php

declare(strict_types=1);

namespace App\Http;

final class Money
{
    /** Parse major units using decimal strings and integer arithmetic only. */
    public static function toMinor(mixed $value): int
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value) && is_finite($value)) {
            // HTTP input preserves the original number token before reaching here.
            // Direct PHP callers get PHP's shortest round-trip decimal representation.
            $value = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        }

        if (!is_string($value) || strlen($value) > 22
            || !preg_match('/\A(0|[1-9][0-9]*)(?:\.([0-9]{1,2}))?\z/D', $value, $parts)) {
            throw new ApiException(422, 'amount must be a positive decimal amount with at most two fractional digits; exponent notation is not supported');
        }

        $minor = ltrim($parts[1] . str_pad($parts[2] ?? '', 2, '0'), '0');
        if ($minor === '' || strlen($minor) > 19
            || (strlen($minor) === 19 && strcmp($minor, '9223372036854775807') > 0)) {
            throw new ApiException(422, 'amount must be positive and fit in signed 64-bit minor units');
        }

        return (int) $minor;
    }

    /** Monetary JSON fields are decimal strings so every kopeck is preserved. */
    public static function fromMinor(int $minor): string
    {
        return (string) intdiv($minor, 100) . '.' . str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }
}
