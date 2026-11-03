<?php

declare(strict_types=1);

namespace ReviewRadar\DTO;

use ReviewRadar\Exceptions\ValidationException;

final class LoginRequest
{
    public function __construct(
        public readonly string $email,
        public readonly string $password,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws ValidationException
     */
    public static function fromArray(array $payload): self
    {
        $errors = [];

        $email = isset($payload['email']) && is_string($payload['email'])
            ? strtolower(trim($payload['email']))
            : '';
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 191) {
            $errors['email'] = 'A valid email address is required.';
        }

        $password = isset($payload['password']) && is_string($payload['password'])
            ? $payload['password']
            : '';
        if ($password === '') {
            $errors['password'] = 'Password is required.';
        }

        if ($errors !== []) {
            throw new ValidationException('The login payload failed validation.', $errors);
        }

        return new self(email: $email, password: $password);
    }
}
