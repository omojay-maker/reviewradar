<?php

declare(strict_types=1);

use ReviewRadar\Auth\Authenticator;
use ReviewRadar\Exceptions\AuthenticationException;
use ReviewRadar\Http\JsonResponse;
use ReviewRadar\Support\Database;

require dirname(__DIR__) . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    JsonResponse::error('Method not allowed. Use GET.', 405);
}

try {
    $company = Authenticator::requireCompany();
    $pdo = Database::pdo();
    $stmt = $pdo->prepare(<<<'SQL'
        SELECT id, source, author_name, rating, raw_text, sentiment, score,
               categories, summary, requires_action, source_url, created_at
        FROM reviews
        WHERE company_id = :company_id
        ORDER BY created_at DESC, id DESC
        SQL);
    $stmt->execute(['company_id' => $company->id]);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reviewradar-reviews-' . gmdate('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Unable to open CSV output stream.');
    }
    fputcsv($output, ['id', 'source', 'author', 'rating', 'review', 'sentiment', 'score', 'categories', 'summary', 'requires_action', 'source_url', 'created_at'], ',', '"', '');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $values = array_values($row);
        foreach ($values as &$value) {
            $value = (string) ($value ?? '');
            if (preg_match('/^\s*[=+\-@]/u', $value) === 1) {
                $value = "'" . $value;
            }
        }
        unset($value);
        fputcsv($output, $values, ',', '"', '');
    }
    fclose($output);
    exit;
} catch (AuthenticationException $e) {
    JsonResponse::error($e->getMessage(), 401);
} catch (Throwable $e) {
    error_log('[export_reviews] Unhandled: ' . $e::class . ' ' . $e->getMessage());
    JsonResponse::error('Unable to export reviews.', 500);
}
