<?php

declare(strict_types=1);

use ReviewRadar\Auth\Authenticator;
use ReviewRadar\DTO\LoginRequest;
use ReviewRadar\Exceptions\AuthenticationException;
use ReviewRadar\Exceptions\ValidationException;
use ReviewRadar\Exceptions\RateLimitException;
use ReviewRadar\Http\JsonResponse;
use ReviewRadar\Http\Request;
use ReviewRadar\Repositories\CompanyRepository;
use ReviewRadar\Support\Database;
use ReviewRadar\Support\RateLimiter;

require dirname(__DIR__) . '/bootstrap.php';

/**
 * POST /api/login.php
 *
 * Body: { "email": "...", "password": "..." }
 * On success: sets the reviewradar_session cookie and returns the public profile.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    JsonResponse::error('Method not allowed. Use POST.', 405);
}

try {
    RateLimiter::enforce('login', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 10, 900);
    $request = LoginRequest::fromArray(Request::jsonBody());

    $company = (new CompanyRepository(Database::pdo()))->findByEmail($request->email);
    if ($company === null || !password_verify($request->password, $company->passwordHash)) {
        throw new AuthenticationException('Invalid email or password.');
    }
    if (!$company->isActive) {
        throw new AuthenticationException('This company account is suspended.');
    }

    Authenticator::login($company->id);

    JsonResponse::success([
        'company' => $company->toPublicArray(),
    ]);
} catch (RateLimitException $e) {
    header('Retry-After: ' . $e->retryAfterSeconds);
    JsonResponse::error($e->getMessage(), 429);
} catch (ValidationException $e) {
    JsonResponse::error($e->getMessage(), 422, $e->errors());
} catch (AuthenticationException $e) {
    JsonResponse::error($e->getMessage(), 401);
} catch (Throwable $e) {
    error_log('[login] Unhandled: ' . $e::class . ' ' . $e->getMessage());
    JsonResponse::error('Internal server error.', 500);
}
