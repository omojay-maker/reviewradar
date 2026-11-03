<?php

declare(strict_types=1);

use ReviewRadar\Auth\Authenticator;
use ReviewRadar\Exceptions\AuthenticationException;
use ReviewRadar\Exceptions\RateLimitException;
use ReviewRadar\Exceptions\ValidationException;
use ReviewRadar\Http\JsonResponse;
use ReviewRadar\Support\RateLimiter;
use ReviewRadar\Support\ReviewImportParser;

require dirname(__DIR__) . '/bootstrap.php';

/**
 * POST /api/parse_import.php
 *
 * Parses an uploaded review export without calling Groq. The dashboard uses
 * this to turn CSV, TSV, JSON, PDF, XLSX, DOCX, TXT or HTML into normalized
 * rows, then sends batches of 25 to /api/import_reviews.php.
 *
 * Request: multipart/form-data with a "file" field.
 * Response: {"rows": [...], "count": 123, "filename": "reviews.pdf"}
 *
 * Auth: X-API-Key | Authorization: Bearer <JWT> | session cookie.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    JsonResponse::error('Method not allowed. Use POST.', 405);
}

if (function_exists('set_time_limit')) {
    @set_time_limit(120);
}

try {
    $company = Authenticator::requireCompany();
    RateLimiter::enforce('review-parse', (string) $company->id, 30, 60);

    $upload = $_FILES['file'] ?? null;
    if (!is_array($upload) || !isset($upload['tmp_name'])) {
        JsonResponse::error('Upload a review file in the "file" field.', 422);
    }

    $uploadError = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
        JsonResponse::error('The import file is larger than the server upload limit.', 413);
    }
    if ($uploadError !== UPLOAD_ERR_OK) {
        JsonResponse::error('The import file upload failed. Please try again.', 422);
    }

    $size = (int) ($upload['size'] ?? 0);
    if ($size <= 0) {
        JsonResponse::error('The import file is empty.', 422);
    }
    if ($size > ReviewImportParser::MAX_BYTES) {
        JsonResponse::error('The import file must be 20 MB or smaller.', 413);
    }
    if (!is_uploaded_file((string) $upload['tmp_name'])) {
        JsonResponse::error('The import file upload is invalid.', 400);
    }

    $contents = file_get_contents((string) $upload['tmp_name']);
    if ($contents === false) {
        JsonResponse::error('Unable to read the import file.', 500);
    }

    $filename = (string) ($upload['name'] ?? '');
    $rows = ReviewImportParser::parse($contents, $filename);

    JsonResponse::success([
        'rows' => $rows,
        'count' => count($rows),
        'filename' => $filename,
    ]);
} catch (RateLimitException $e) {
    header('Retry-After: ' . $e->retryAfterSeconds);
    JsonResponse::error($e->getMessage(), 429);
} catch (ValidationException $e) {
    JsonResponse::error($e->getMessage(), 422, $e->errors());
} catch (AuthenticationException $e) {
    JsonResponse::error($e->getMessage(), 401);
} catch (Throwable $e) {
    error_log('[parse_import] Unhandled: ' . $e::class . ' ' . $e->getMessage());
    JsonResponse::error('Unable to parse the import file.', 500);
}
