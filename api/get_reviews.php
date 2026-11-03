<?php

declare(strict_types=1);

use ReviewRadar\Auth\Authenticator;
use ReviewRadar\Exceptions\AuthenticationException;
use ReviewRadar\Http\JsonResponse;
use ReviewRadar\Repositories\ReviewRepository;
use ReviewRadar\Support\Database;

require dirname(__DIR__) . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    JsonResponse::error('Method not allowed. Use GET.', 405);
}

try {
    $company = Authenticator::requireCompany();
    $limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT);
    if ($limit === false || ($limit !== null && ($limit < 1 || $limit > 250))) {
        JsonResponse::error('Query parameter "limit" must be between 1 and 250.', 422);
    }
    $offset = filter_input(INPUT_GET, 'offset', FILTER_VALIDATE_INT);
    if ($offset === false || ($offset !== null && ($offset < 0 || $offset > 10000000))) {
        JsonResponse::error('Query parameter "offset" must be between 0 and 10000000.', 422);
    }
    $repository = new ReviewRepository(Database::pdo());
    $reviews = $repository->listForCompany($company->id, $limit ?? 100, $offset ?? 0);
    JsonResponse::success([
        'reviews' => $reviews,
        'count' => $repository->countForCompany($company->id),
        'limit' => $limit ?? 100,
        'offset' => $offset ?? 0,
    ]);
} catch (AuthenticationException $e) {
    JsonResponse::error($e->getMessage(), 401);
} catch (Throwable $e) {
    error_log('[get_reviews] Unhandled: ' . $e::class . ' ' . $e->getMessage());
    JsonResponse::error('Internal server error.', 500);
}
