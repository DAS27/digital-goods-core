<?php

declare(strict_types=1);

namespace App\Http;

use DateTimeImmutable;
use DateTimeZone;

final class Input
{
    public static function identifier(mixed $value, string $field): string
    {
        if (!is_string($value) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value)) {
            throw new ApiException(422, "$field must contain 1 to 128 letters, digits, dots, underscores, colons or hyphens, starting with a letter or digit");
        }

        return $value;
    }

    public static function currency(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[A-Z]{3}\z/D', $value)) {
            throw new ApiException(422, 'currency must be a three-letter uppercase currency code');
        }

        return $value;
    }

    /** Return a canonical UTC timestamp; equivalent offsets hash identically. */
    public static function timestamp(mixed $value): string
    {
        if (!is_string($value) || !preg_match(
            '/\A([0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2})(?:\.([0-9]{1,6}))?(Z|[+-][0-9]{2}:[0-9]{2})\z/D',
            $value,
            $parts,
        )) {
            throw new ApiException(422, 'created_at must be an ISO8601 timestamp with a timezone and at most six fractional digits');
        }

        $offset = $parts[3] === 'Z' ? '+00:00' : $parts[3];
        $offsetHours = (int) substr($offset, 1, 2);
        $offsetMinutes = (int) substr($offset, 4, 2);
        if (substr($parts[1], 0, 4) === '0000'
            || $offsetHours > 14
            || $offsetMinutes > 59
            || ($offsetHours === 14 && $offsetMinutes !== 0)) {
            throw new ApiException(422, 'created_at has an invalid date or timezone');
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s.uP',
            $parts[1] . '.' . str_pad($parts[2] ?? '', 6, '0') . $offset,
        );
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))) {
            throw new ApiException(422, 'created_at has an invalid date or time');
        }

        $utc = $date->setTimezone(new DateTimeZone('UTC'));
        if ((int) $utc->format('Y') < 1 || (int) $utc->format('Y') > 9999) {
            throw new ApiException(422, 'created_at is outside the supported year range');
        }

        return $utc->format('Y-m-d\TH:i:s.u\Z');
    }
}
