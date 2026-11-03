<?php

declare(strict_types=1);

use ReviewRadar\Auth\Authenticator;
use ReviewRadar\Exceptions\AuthenticationException;
use ReviewRadar\Http\JsonResponse;
use ReviewRadar\Repositories\ReviewRepository;
use ReviewRadar\Support\Database;

require dirname(__DIR__) . '/bootstrap.php';

/**
 * GET /api/get_analytics.php?days=30
 *
 * Aggregated customer-health metrics for the authenticated tenant:
 *   - total reviews, average sentiment score, average source rating
 *   - urgent action count (requires_action = 1)
 *   - sentiment breakdown
 *   - top complaint categories (from negative reviews)
 *   - daily review volume and average score trend
 *   - latest urgent reviews (alert feed)
 *
 * Query parameters:
 *   days (int, 1-365, default 30) - rolling window ending now (UTC).
 *
 * Auth: X-API-Key | Authorization: Bearer <JWT> | session cookie.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    JsonResponse::error('Method not allowed. Use GET.', 405);
}

try {
    $company = Authenticator::requireCompany();

    $days = filter_input(INPUT_GET, 'days', FILTER_VALIDATE_INT);
    if ($days === null) {
        $days = 30;
    } elseif ($days === false || $days < 1 || $days > 365) {
        JsonResponse::error('Query parameter "days" must be an integer between 1 and 365.', 422);
    }

    $to = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $from = $to->modify(sprintf('-%d days', $days));

    $repository = new ReviewRepository(Database::pdo());
    $analytics = $repository->analytics($company->id, $from, $to);

    JsonResponse::success([
        'company' => $company->toPublicArray(),
        'range' => [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'days' => $days,
        ],
        'totals' => [
            'reviews' => $analytics['totals']['total_reviews'],
            'average_score' => $analytics['totals']['average_score'],
            'average_rating' => $analytics['totals']['average_rating'],
            'urgent_actions' => $analytics['totals']['urgent_action_count'],
        ],
        'sentiment_breakdown' => $analytics['sentiment_breakdown'],
        'top_complaint_categories' => $analytics['top_complaint_categories'],
        'trend' => $analytics['trend'],
        'recent_urgent_reviews' => $analytics['recent_urgent_reviews'],
    ]);
} catch (AuthenticationException $e) {
    JsonResponse::error($e->getMessage(), 401);
} catch (Throwable $e) {
    error_log('[get_analytics] Unhandled: ' . $e::class . ' ' . $e->getMessage());
    JsonResponse::error('Internal server error.', 500);
}
