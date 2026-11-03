<?php

declare(strict_types=1);

use ReviewRadar\Support\Database;

require dirname(__DIR__) . '/bootstrap.php';

$attempts = 30;
$pdo = null;

for ($attempt = 1; $attempt <= $attempts; $attempt++) {
    try {
        $pdo = Database::pdo();
        break;
    } catch (Throwable $e) {
        if ($attempt === $attempts) {
            fwrite(STDERR, 'Database connection failed: ' . $e->getMessage() . PHP_EOL);
            exit(1);
        }
        sleep(2);
    }
}

$schemaPath = dirname(__DIR__) . '/schema.sql';
$schema = file_get_contents($schemaPath);
if ($schema === false) {
    fwrite(STDERR, 'Unable to read schema.sql' . PHP_EOL);
    exit(1);
}

try {
    $pdo->exec($schema);
} catch (Throwable $e) {
    fwrite(STDERR, 'Schema migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'ReviewRadar schema applied.' . PHP_EOL);
