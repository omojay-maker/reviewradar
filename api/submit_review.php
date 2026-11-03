<?php

declare(strict_types=1);

use ReviewRadar\Auth\Authenticator;
use ReviewRadar\DTO\SubmitReviewRequest;
use ReviewRadar\Exceptions\AnalysisException;
use ReviewRadar\Exceptions\AuthenticationException;
use ReviewRadar\Exceptions\ValidationException;
use ReviewRadar\Exceptions\RateLimitException;
use ReviewRadar\Http\JsonResponse;
use ReviewRadar\Repositories\ReviewRepository;
use ReviewRadar\Services\GroqAnalyzer;
use ReviewRadar\Support\Database;
use ReviewRadar\Support\RateLimiter;

require dirname(__DIR__) . '/bootstrap.php';

/**
 * POST /api/submit_review.php
 *
 * Ingests a single review for the authenticated tenant, runs it through the
 * Groq sentiment engine and stores the structured result under company_id.
 *
 * Request body (application/json):
 * {
 *   "text": "The product broke after two days...",
 *   "author_name": "Jane",          // optional
 *   "rating": 2,                    // optional, 1-5
 *   "source": "webhook",            // optional, default "api"
 *   "external_id": "g2-12345",      // optional, idempotency key per company
 *   "source_url": "https://..."     // optional
 * }
 *
 * Auth: X-API-Key | Authorization: Bearer <JWT> | session cookie.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    JsonResponse::error('Method not allowed. Use POST.', 405);
}

try {
    // Authenticate first: never leak payload/schema state to anonymous callers.
    $company = Authenticator::requireCompany();
    RateLimiter::enforce('review-ingest', (string) $company->id, 30, 60);

    $rawBody = file_get_contents('php://input');
    $payload = is_string($rawBody) ? json_decode($rawBody, true) : null;
    if (!is_array($payload)) {
        JsonResponse::error('Request body must be a valid JSON object.', 400);
    }

    // Tenant identity comes only from the authenticated credential. Never trust
    // a company_id supplied in the review body.
    $request = SubmitReviewRequest::fromArray($payload, $company->id);

    $repository = new ReviewRepository(Database::pdo());

    $analyzer = GroqAnalyzer::fromConfig();
    $analysis = $analyzer->analyze($request->text);

    $reviewId = $repository->create($request, $analysis, $analyzer->getModel());

    JsonResponse::success([
        'review_id' => $reviewId,
        'company_id' => $company->id,
        'analysis' => $analysis->toArray(),
    ], 201);
} catch (ValidationException $e) {
    JsonResponse::error($e->getMessage(), 422, $e->errors());
} catch (RateLimitException $e) {
    header('Retry-After: ' . $e->retryAfterSeconds);
    JsonResponse::error($e->getMessage(), 429);
} catch (AuthenticationException $e) {
    JsonResponse::error($e->getMessage(), 401);
} catch (AnalysisException $e) {
    error_log('[submit_review] ' . $e->getMessage());
    JsonResponse::error('Unable to analyze the review at this time. Please retry.', 502);
} catch (Throwable $e) {
    error_log('[submit_review] Unhandled: ' . $e::class . ' ' . $e->getMessage());
    JsonResponse::error('Internal server error.', 500);
}
