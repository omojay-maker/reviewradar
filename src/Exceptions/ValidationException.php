<?php

declare(strict_types=1);

namespace ReviewRadar\Exceptions;

use RuntimeException;
use Throwable;

final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string> $errors Field name => human readable message.
     */
    public function __construct(
        string $message,
        private readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** @return array<string, string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
