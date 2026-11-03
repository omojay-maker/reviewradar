<?php

declare(strict_types=1);

namespace ReviewRadar\Repositories;

use PDO;
use PDOException;
use ReviewRadar\Entities\Company;
use ReviewRadar\Exceptions\ValidationException;

final class CompanyRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function find(int $companyId): ?Company
    {
        $stmt = $this->pdo->prepare('SELECT * FROM companies WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $companyId]);
        $row = $stmt->fetch();

        return is_array($row) ? Company::fromRow($row) : null;
    }

    public function findByApiKey(string $apiKey): ?Company
    {
        $stmt = $this->pdo->prepare('SELECT * FROM companies WHERE api_key = :api_key LIMIT 1');
        $stmt->execute(['api_key' => $apiKey]);
        $row = $stmt->fetch();

        return is_array($row) ? Company::fromRow($row) : null;
    }

    public function findByEmail(string $email): ?Company
    {
        $stmt = $this->pdo->prepare('SELECT * FROM companies WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        return is_array($row) ? Company::fromRow($row) : null;
    }

    public function slugExists(string $slug): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM companies WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $slug]);

        return $stmt->fetch() !== false;
    }

    public function rotateApiKey(int $companyId, string $apiKey): void
    {
        $stmt = $this->pdo->prepare('UPDATE companies SET api_key = :api_key WHERE id = :id');
        $stmt->execute(['api_key' => $apiKey, 'id' => $companyId]);
    }

    public function deleteTenant(int $companyId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM companies WHERE id = :id');
        $stmt->execute(['id' => $companyId]);
    }

    /**
     * @throws ValidationException When the email is already registered (unique index).
     */
    public function createTenant(
        string $name,
        string $slug,
        string $email,
        string $passwordHash,
        string $apiKey,
    ): int {
        $sql = <<<'SQL'
            INSERT INTO companies (name, slug, email, password_hash, api_key, is_active)
            VALUES (:name, :slug, :email, :password_hash, :api_key, 1)
            RETURNING id
            SQL;

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'name' => $name,
                'slug' => $slug,
                'email' => $email,
                'password_hash' => $passwordHash,
                'api_key' => $apiKey,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'email')) {
                throw new ValidationException(
                    'An account with this email already exists.',
                    ['email' => 'An account with this email already exists.'],
                    $e,
                );
            }
            throw $e;
        }

        return (int) $stmt->fetchColumn();
    }
}
