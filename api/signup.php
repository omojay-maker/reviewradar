<?php

declare(strict_types=1);

use ReviewRadar\Auth\Authenticator;
use ReviewRadar\DTO\SignupRequest;
use ReviewRadar\Exceptions\ValidationException;
use ReviewRadar\Exceptions\RateLimitException;
use ReviewRadar\Http\JsonResponse;
use ReviewRadar\Http\Request;
use ReviewRadar\Repositories\CompanyRepository;
use ReviewRadar\Support\Database;
use ReviewRadar\Support\RateLimiter;

require dirname(__DIR__) . '/bootstrap.php';

/**
 * POST /api/signup.php
 *
 * Body: { "company_name": "...", "email": "...", "password": "..." }
 * Creates a new tenant, establishes the session and returns
 * the public company profile. API keys are managed by session-only endpoints.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    JsonResponse::error('Method not allowed. Use POST.', 405);
}

try {
    RateLimiter::enforce('signup', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 10, 900);
    $request = SignupRequest::fromArray(Request::jsonBody());

    $repository = new CompanyRepository(Database::pdo());

    if ($repository->findByEmail($request->email) !== null) {
        throw new ValidationException(
            'An account with this email already exists.',
            ['email' => 'An account with this email already exists.'],
        );
    }

    $slug = preg_replace('/[^a-z0-9]+/u', '-', mb_strtolower(trim($request->companyName))) ?? '';
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'company';
    }
    $slug = mb_substr($slug, 0, 150);

    $suffix = 2;
    $candidate = $slug;
    while ($repository->slugExists($candidate)) {
        $candidate = $slug . '-' . $suffix;
        $suffix++;
        if ($suffix > 50) {
            $candidate = $slug . '-' . bin2hex(random_bytes(3));
            break;
        }
    }
    $slug = $candidate;

    $apiKey = bin2hex(random_bytes(32));
    $passwordHash = password_hash($request->password, PASSWORD_DEFAULT);

    $companyId = $repository->createTenant(
        name: $request->companyName,
        slug: $slug,
        email: $request->email,
        passwordHash: $passwordHash,
        apiKey: $apiKey,
    );

    Authenticator::login($companyId);

    $company = $repository->find($companyId);
    if ($company === null) {
        JsonResponse::error('Account created but could not be loaded. Please log in.', 500);
    }

    JsonResponse::success([
        'company' => $company->toPublicArray(),
    ], 201);
} catch (RateLimitException $e) {
    header('Retry-After: ' . $e->retryAfterSeconds);
    JsonResponse::error($e->getMessage(), 429);
} catch (ValidationException $e) {
    JsonResponse::error($e->getMessage(), 422, $e->errors());
} catch (Throwable $e) {
    error_log('[signup] Unhandled: ' . $e::class . ' ' . $e->getMessage());
    JsonResponse::error('Internal server error.', 500);
}
