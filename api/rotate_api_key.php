<?php

declare(strict_types=1);

use ReviewRadar\Auth\Authenticator;
use ReviewRadar\Exceptions\AuthenticationException;
use ReviewRadar\Exceptions\RateLimitException;
use ReviewRadar\Http\JsonResponse;
use ReviewRadar\Repositories\CompanyRepository;
use ReviewRadar\Support\Database;
use ReviewRadar\Support\RateLimiter;

require dirname(__DIR__) . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    JsonResponse::error('Method not allowed. Use POST.', 405);
}

try {
    $company = Authenticator::requireSessionCompany();
    RateLimiter::enforce('api-key-rotation', (string) $company->id, 3, 3600);
    $apiKey = bin2hex(random_bytes(32));
    (new CompanyRepository(Database::pdo()))->rotateApiKey($company->id, $apiKey);
    JsonResponse::success(['api_key' => $apiKey]);
} catch (AuthenticationException $e) {
    JsonResponse::error($e->getMessage(), 401);
} catch (RateLimitException $e) {
    header('Retry-After: ' . $e->retryAfterSeconds);
    JsonResponse::error($e->getMessage(), 429);
} catch (Throwable $e) {
    error_log('[rotate_api_key] Unhandled: ' . $e::class . ' ' . $e->getMessage());
    JsonResponse::error('Internal server error.', 500);
}
