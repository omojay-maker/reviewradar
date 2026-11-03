<?php

declare(strict_types=1);

use ReviewRadar\Support\Config;

/**
 * ReviewRadar API bootstrap: PSR-4 autoloading, config load, and restricted CORS.
 * Every public/api/*.php entry point must require this file first.
 */

define('REVIEWRADAR_ROOT', __DIR__);

spl_autoload_register(static function (string $class): void {
    $prefix = 'ReviewRadar\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $path = REVIEWRADAR_ROOT . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

Config::load(REVIEWRADAR_ROOT . '/config/config.php');

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');

// CORS is opt-in and allows exactly one configured origin for API integrations.
$allowedOrigin = Config::string('app.cors_origin');
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($allowedOrigin !== '' && is_string($requestOrigin) && hash_equals($allowedOrigin, $requestOrigin)) {
    header('Access-Control-Allow-Origin: ' . $allowedOrigin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
    header('Vary: Origin');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
