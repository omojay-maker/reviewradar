<?php

declare(strict_types=1);

namespace ReviewRadar\Support;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $pdo = null;

    private function __construct()
    {
    }

    /**
     * Lazy PDO singleton configured for strict, emulated-free PostgreSQL access.
     *
     * @throws RuntimeException When the connection cannot be established.
     */
    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            Config::string('db.host', '127.0.0.1'),
            Config::int('db.port', 5432),
            Config::string('db.name', 'reviewradar'),
            Config::string('db.sslmode', 'prefer'),
        );

        try {
            $pdo = new PDO(
                $dsn,
                Config::string('db.user', 'postgres'),
                Config::string('db.password', ''),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ],
            );
            $pdo->exec("SET TIME ZONE 'UTC'");
        } catch (PDOException $e) {
            throw new RuntimeException('Unable to connect to the database: ' . $e->getMessage(), 0, $e);
        }

        return self::$pdo = $pdo;
    }
}
