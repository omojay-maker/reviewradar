<?php

declare(strict_types=1);

use ReviewRadar\Auth\Authenticator;
use ReviewRadar\DTO\SubmitReviewRequest;
use ReviewRadar\Exceptions\AnalysisException;
use ReviewRadar\Exceptions\AuthenticationException;
use ReviewRadar\Exceptions\RateLimitException;
use ReviewRadar\Exceptions\ValidationException;
use ReviewRadar\Http\JsonResponse;
use ReviewRadar\Http\Request;
use ReviewRadar\Repositories\ReviewRepository;
use ReviewRadar\Services\GroqAnalyzer;
use ReviewRadar\Support\Database;
use ReviewRadar\Support\RateLimiter;
use ReviewRadar\Support\ReviewImportParser;

require dirname(__DIR__) . '/bootstrap.php';

/**
 * POST /api/import_reviews.php
 *
 * Bulk-ingests reviews for the authenticated tenant. Accepts either:
 *   - multipart/form-data with a "file" field in any supported import format
 *   - application/json with {"reviews": [ { ... }, ... ]}
 *
 * Supported file formats: CSV, TSV, JSON, PDF, XLSX, DOCX, TXT and HTML.
 * At most 25 reviews are processed per request; the dashboard and CLI batch
 * larger files automatically. Valid rows are analyzed concurrently via Groq.
 *
 * Auth: X-API-Key | Authorization: Bearer <JWT> | session cookie.
 */

const REVIEW_IMPORT_MAX_BATCH = 25;
const REVIEW_IMPORT_MAX_BYTES = 20_971_520;

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    JsonResponse::error('Method not allowed. Use POST.', 405);
}

if (function_exists('set_time_limit')) {
    @set_time_limit(180);
}

try {
    $company = Authenticator::requireCompany();
    RateLimiter::enforce('review-import', (string) $company->id, 60, 60);

    $upload = $_FILES['file'] ?? null;
    if (is_array($upload) && isset($upload['tmp_name'])) {
        $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadError !== UPLOAD_ERR_OK) {
            JsonResponse::error('The import file upload failed. Please try again.', 422);
        }
        if ((int) ($upload['size'] ?? 0) > REVIEW_IMPORT_MAX_BYTES) {
            JsonResponse::error('The import file must be 20 MB or smaller.', 413);
        }
        if (!is_uploaded_file((string) $upload['tmp_name'])) {
            JsonResponse::error('The import file upload is invalid.', 400);
        }
        $contents = file_get_contents((string) $upload['tmp_name']);
        if ($contents === false) {
            JsonResponse::error('Unable to read the import file.', 500);
        }
        $rows = ReviewImportParser::parse($contents, (string) ($upload['name'] ?? ''));
    } else {
        $rows = ReviewImportParser::fromPayload(Request::jsonBody());
    }

    if ($rows === []) {
        JsonResponse::error('No reviews were found in the import file.', 422);
    }
    if (count($rows) > REVIEW_IMPORT_MAX_BATCH) {
        JsonResponse::error(
            'Import at most ' . REVIEW_IMPORT_MAX_BATCH . ' reviews per request; the dashboard batches larger files automatically.',
            422,
        );
    }

    $repository = new ReviewRepository(Database::pdo());

    $valid = [];
    $errors = [];
    foreach ($rows as $index => $row) {
        try {
            $valid[] = [
                'index' => $index,
                'request' => SubmitReviewRequest::fromArray($row, $company->id),
            ];
        } catch (ValidationException $e) {
            $errors[] = [
                'row' => $index + 1,
                'message' => $e->getMessage(),
                'fields' => $e->errors(),
            ];
        }
    }

    $imported = 0;
    if ($valid !== []) {
        try {
            $analyzer = GroqAnalyzer::fromConfig();
        } catch (AnalysisException $e) {
            error_log('[import_reviews] ' . $e->getMessage());
            JsonResponse::error('Review analysis is not configured. Please contact support.', 502);
        }

        $results = $analyzer->analyzeMany(array_map(
            static fn (array $item): string => $item['request']->text,
            $valid,
        ));

        foreach ($valid as $position => $item) {
            $index = (int) $item['index'];
            /** @var SubmitReviewRequest $request */
            $request = $item['request'];
            $result = $results[$position] ?? ['analysis' => null, 'error' => 'No analysis result.'];
            $analysis = $result['analysis'];

            if ($analysis === null) {
                $message = (string) ($result['error'] ?? 'Analysis failed.');
                error_log('[import_reviews] Analysis failed for row ' . ($index + 1) . ': ' . $message);
                $errors[] = [
                    'row' => $index + 1,
                    'message' => 'This review could not be analyzed and was not imported.',
                ];
                continue;
            }

            try {
                $repository->create($request, $analysis, $analyzer->getModel());
                $imported++;
            } catch (Throwable $e) {
                error_log('[import_reviews] Row ' . ($index + 1) . ' failed: ' . $e::class . ' ' . $e->getMessage());
                $errors[] = [
                    'row' => $index + 1,
                    'message' => 'This review could not be imported.',
                ];
            }
        }
    }

    JsonResponse::success([
        'total' => count($rows),
        'accepted' => count($valid),
        'imported' => $imported,
        'failed' => count($errors),
        'errors' => $errors,
    ]);
} catch (RateLimitException $e) {
    header('Retry-After: ' . $e->retryAfterSeconds);
    JsonResponse::error($e->getMessage(), 429);
} catch (ValidationException $e) {
    JsonResponse::error($e->getMessage(), 422, $e->errors());
} catch (AuthenticationException $e) {
    JsonResponse::error($e->getMessage(), 401);
} catch (Throwable $e) {
    error_log('[import_reviews] Unhandled: ' . $e::class . ' ' . $e->getMessage());
    JsonResponse::error('Unable to import reviews.', 500);
}
