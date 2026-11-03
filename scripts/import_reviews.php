<?php

declare(strict_types=1);

use ReviewRadar\Support\ReviewImportParser;

require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(2);
}

$options = getopt('', ['api:', 'key::', 'file:', 'batch::', 'help']);

$usage = <<<'TEXT'
Usage:
  php scripts/import_reviews.php --api=http://127.0.0.1:8080 --key=COMPANY_API_KEY --file=reviews.csv [--batch=25]

The API key can also be supplied with the REVIEWRADAR_API_KEY environment variable.
Accepts CSV, TSV, JSON, PDF, XLSX, DOCX, TXT and HTML. At most 25 reviews are sent per request; the script batches automatically.
TEXT;

if (isset($options['help']) || !isset($options['api'], $options['file'])) {
    fwrite(STDOUT, $usage . "\n");
    exit(isset($options['help']) ? 0 : 2);
}

$api = rtrim((string) $options['api'], '/');
$key = isset($options['key']) && is_string($options['key']) ? $options['key'] : (string) (getenv('REVIEWRADAR_API_KEY') ?: '');
$file = (string) $options['file'];
$batchSize = isset($options['batch']) ? (int) $options['batch'] : 25;
$batchSize = max(1, min(25, $batchSize));

if ($key === '') {
    fwrite(STDERR, "An API key is required (--key or REVIEWRADAR_API_KEY).\n");
    exit(2);
}
if (!is_file($file) || !is_readable($file)) {
    fwrite(STDERR, "Cannot read import file: {$file}\n");
    exit(2);
}

$contents = file_get_contents($file);
if ($contents === false) {
    fwrite(STDERR, "Failed to read import file: {$file}\n");
    exit(2);
}

try {
    $rows = ReviewImportParser::parse($contents, basename($file));
} catch (Throwable $e) {
    fwrite(STDERR, 'Import file error: ' . $e->getMessage() . "\n");
    exit(2);
}

$total = count($rows);
$imported = 0;
$failed = 0;
$endpoint = $api . '/api/import_reviews.php';

fwrite(STDOUT, "Importing {$total} reviews from {$file} in batches of {$batchSize}...\n");

for ($offset = 0; $offset < $total; $offset += $batchSize) {
    $chunk = array_slice($rows, $offset, $batchSize);
    $payload = json_encode(['reviews' => $chunk], JSON_THROW_ON_ERROR);

    $handle = curl_init($endpoint);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: ' . $key,
        ],
        CURLOPT_TIMEOUT => 180,
    ]);

    $response = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if ($response === false) {
        fwrite(STDERR, "Request failed: {$error}\n");
        exit(1);
    }

    $decoded = json_decode($response, true);

    if ($status !== 200 || !is_array($decoded) || ($decoded['success'] ?? false) !== true) {
        $message = is_array($decoded) ? (string) ($decoded['error']['message'] ?? $response) : $response;
        fwrite(STDERR, "Import failed at row " . ($offset + 1) . " (HTTP {$status}): {$message}\n");
        exit(1);
    }

    $data = $decoded['data'];
    $imported += (int) $data['imported'];
    $failed += (int) $data['failed'];

    foreach ($data['errors'] ?? [] as $item) {
        fwrite(STDERR, 'Row ' . ($offset + (int) $item['row']) . ': ' . (string) $item['message'] . "\n");
    }

    fwrite(STDOUT, 'Processed ' . min($offset + $batchSize, $total) . "/{$total} (imported {$imported}, failed {$failed})\n");
}

fwrite(STDOUT, "\nImported {$imported} of {$total} reviews. Failed: {$failed}.\n");

exit($failed > 0 ? 1 : 0);
