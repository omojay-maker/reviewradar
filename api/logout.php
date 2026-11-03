<?php

declare(strict_types=1);

use ReviewRadar\Auth\Authenticator;
use ReviewRadar\Http\JsonResponse;

require dirname(__DIR__) . '/bootstrap.php';

/**
 * POST /api/logout.php - destroys the dashboard session.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    JsonResponse::error('Method not allowed. Use POST.', 405);
}

try {
    Authenticator::logout();
    JsonResponse::success(['message' => 'Logged out.']);
} catch (Throwable $e) {
    error_log('[logout] Unhandled: ' . $e::class . ' ' . $e->getMessage());
    JsonResponse::error('Internal server error.', 500);
}
