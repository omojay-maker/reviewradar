<?php

declare(strict_types=1);

namespace ReviewRadar\Exceptions;

use RuntimeException;

final class RateLimitException extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds = 60)
    {
        parent::__construct('Too many requests. Please wait before trying again.');
    }
}
