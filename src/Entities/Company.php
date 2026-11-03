<?php

declare(strict_types=1);

namespace ReviewRadar\Entities;

final class Company
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $slug,
        public readonly string $email,
        public readonly string $apiKey,
        public readonly bool $isActive,
        public readonly string $passwordHash,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            name: (string) $row['name'],
            slug: (string) $row['slug'],
            email: (string) $row['email'],
            apiKey: (string) $row['api_key'],
            isActive: (bool) $row['is_active'],
            passwordHash: (string) $row['password_hash'],
        );
    }

    /**
     * Public representation for API responses - never exposes the API key
     * or the password hash.
     *
     * @return array{id: int, name: string, slug: string, email: string}
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'email' => $this->email,
        ];
    }
}
