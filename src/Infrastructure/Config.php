<?php

declare(strict_types=1);

namespace App\Infrastructure;

final class Config
{
    public static function get(string $key, string $default = ''): string
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }
}
