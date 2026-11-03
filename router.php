<?php

declare(strict_types=1);

/**
 * Development router for PHP's built-in server.
 *
 * Usage: php -d upload_max_filesize=20M -d post_max_size=25M -S 127.0.0.1:8080 -t . router.php
 *
 * The built-in server ignores .htaccess, so this router blocks dotfiles,
 * application source/config directories, tests, and repository metadata that
 * would otherwise be served as static files. Apache and Nginx deployments
 * must enforce equivalent rules (.htaccess is included for Apache).
 */

$uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = rawurldecode(is_string($uri) ? $uri : '/');
$query = (string) ($_SERVER['QUERY_STRING'] ?? '');
$suffix = $query === '' ? '' : '?' . $query;

$blocked = $path === ''
    || str_contains($path, "\0")
    || $path === '/.'
    || str_starts_with($path, '/.')
    || preg_match('#/(\.|\.[^/]+)#', $path) === 1
    || preg_match('#/(src|config|tests|scripts)(/|$)#i', $path) === 1
    || preg_match('#/(schema\.sql|README\.md|composer\.(json|lock)|bootstrap\.php|router\.php)$#i', $path) === 1;

if ($blocked) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not found\n";
    exit;
}

if (preg_match('#^/([a-z0-9-]+)\.html$#i', $path, $matches) === 1) {
    $slug = strtolower($matches[1]);
    if (is_file(__DIR__ . '/' . $slug . '.html')) {
        header('Location: ' . ($slug === 'index' ? '/login' : '/' . $slug) . $suffix, true, 301);
        exit;
    }
}

if ($path === '/') {
    $file = __DIR__ . '/index.html';
    if (is_file($file)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($file);
        exit;
    }
}

if ($path === '/index' || $path === '/index/') {
    $file = __DIR__ . '/index.html';
    if (is_file($file)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($file);
        exit;
    }
}

if (preg_match('#^/([a-z0-9-]+)/$#i', $path, $matches) === 1) {
    $slug = strtolower($matches[1]);
    if (is_file(__DIR__ . '/' . $slug . '.html')) {
        header('Location: /' . $slug . $suffix, true, 301);
        exit;
    }
}

if (preg_match('#^/([a-z0-9-]+)$#i', $path, $matches) === 1) {
    $file = __DIR__ . '/' . strtolower($matches[1]) . '.html';
    if (is_file($file)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($file);
        exit;
    }
}

return false;
