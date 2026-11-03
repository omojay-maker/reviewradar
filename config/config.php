<?php

declare(strict_types=1);

/**
 * Central configuration. Environment variables take precedence; an optional
 * .env file at the project root is loaded when the variable is not set.
 */

$dotenvPath = dirname(__DIR__) . '/.env';
if (is_file($dotenvPath)) {
    $lines = file($dotenvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\"'");
            if ($key !== '' && getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }
}

return [
    'app' => [
        'name' => getenv('APP_NAME') ?: 'ReviewRadar',
        'env' => getenv('APP_ENV') ?: 'production',
        'cors_origin' => getenv('APP_CORS_ORIGIN') ?: '',
        'jwt_secret' => getenv('JWT_SECRET') ?: '',
        'jwt_ttl_seconds' => (int) (getenv('JWT_TTL') ?: 3600),
    ],
    'db' => [
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'name' => getenv('DB_NAME') ?: 'reviewradar',
        'user' => getenv('DB_USER') ?: '',
        'password' => getenv('DB_PASSWORD') ?: '',
        'charset' => 'utf8mb4',
    ],
    'groq' => [
        'api_key' => getenv('GROQ_API_KEY') ?: '',
        'model' => getenv('GROQ_MODEL') ?: 'llama3-70b-8192',
        'endpoint' => getenv('GROQ_ENDPOINT') ?: 'https://api.groq.com/openai/v1/chat/completions',
        'timeout' => (int) (getenv('GROQ_TIMEOUT') ?: 30),
        'concurrency' => (int) (getenv('GROQ_CONCURRENCY') ?: 5),
    ],
];
