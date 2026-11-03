<?php

declare(strict_types=1);

use ReviewRadar\Auth\Authenticator;
use ReviewRadar\Exceptions\AuthenticationException;
use ReviewRadar\Exceptions\RateLimitException;
use ReviewRadar\Exceptions\ValidationException;
use ReviewRadar\Http\JsonResponse;
use ReviewRadar\Http\Request;
use ReviewRadar\Repositories\CompanyRepository;
use ReviewRadar\Support\Database;
use ReviewRadar\Support\RateLimiter;

require dirname(__DIR__) . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    JsonResponse::error('Method not allowed. Use POST.', 405);
}

try {
    $company = Authenticator::requireSessionCompany();
    RateLimiter::enforce('account-deletion', (string) $company->id, 3, 86400);
    $payload = Request::jsonBody();
    $password = $payload['password'] ?? '';
    $confirmation = $payload['company_name'] ?? '';
    if (!is_string($password) || $password === '' || !is_string($confirmation)) {
        throw new ValidationException('Enter your password and company name to delete this workspace.');
    }
    if (!password_verify($password, $company->passwordHash)) {
        throw new AuthenticationException('The password is incorrect.');
    }
    if (!hash_equals($company->name, trim($confirmation))) {
        throw new ValidationException('The company name does not match.');
    }
    (new CompanyRepository(Database::pdo()))->deleteTenant($company->id);
    Authenticator::logout();
    JsonResponse::success(['message' => 'The workspace and its review data were deleted.']);
} catch (RateLimitException $e) {
    header('Retry-After: ' . $e->retryAfterSeconds);
    JsonResponse::error($e->getMessage(), 429);
} catch (ValidationException $e) {
    JsonResponse::error($e->getMessage(), 422, $e->errors());
} catch (AuthenticationException $e) {
    JsonResponse::error($e->getMessage(), 401);
} catch (Throwable $e) {
    error_log('[delete_account] Unhandled: ' . $e::class . ' ' . $e->getMessage());
    JsonResponse::error('Unable to delete this workspace.', 500);
}
