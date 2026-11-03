<?php

declare(strict_types=1);

namespace ReviewRadar\DTO;

use ReviewRadar\Exceptions\ValidationException;

final class SignupRequest
{
    public const MIN_PASSWORD_LENGTH = 8;
    public const MAX_PASSWORD_LENGTH = 72;

    public function __construct(
        public readonly string $companyName,
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

        $companyName = isset($payload['company_name']) && is_string($payload['company_name'])
            ? trim($payload['company_name'])
            : '';
        if ($companyName === '' || mb_strlen($companyName) < 2 || mb_strlen($companyName) > 255) {
            $errors['company_name'] = 'Company name must be between 2 and 255 characters.';
        }

        $email = isset($payload['email']) && is_string($payload['email'])
            ? strtolower(trim($payload['email']))
            : '';
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 191) {
            $errors['email'] = 'A valid email address is required.';
        }

        $password = isset($payload['password']) && is_string($payload['password'])
            ? $payload['password']
            : '';
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            $errors['password'] = 'Password must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters.';
        } elseif (strlen($password) > self::MAX_PASSWORD_LENGTH) {
            $errors['password'] = 'Password must not exceed ' . self::MAX_PASSWORD_LENGTH . ' characters.';
        }

        if ($errors !== []) {
            throw new ValidationException('The signup payload failed validation.', $errors);
        }

        return new self(companyName: $companyName, email: $email, password: $password);
    }
}
