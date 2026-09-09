<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/** Only safe, client-facing messages belong in this exception. */
final class ApiException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
