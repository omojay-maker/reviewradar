<?php

declare(strict_types=1);

use ReviewRadar\Auth\Jwt;
use ReviewRadar\DTO\LoginRequest;
use ReviewRadar\DTO\ReviewAnalysis;
use ReviewRadar\DTO\SignupRequest;
use ReviewRadar\DTO\SubmitReviewRequest;
use ReviewRadar\Exceptions\AnalysisException;
use ReviewRadar\Exceptions\RateLimitException;
use ReviewRadar\Exceptions\ValidationException;
use ReviewRadar\Support\Config;
use ReviewRadar\Support\RateLimiter;
use ReviewRadar\Support\ReviewImportParser;

define('ROOT', dirname(__DIR__));

require ROOT . '/bootstrap.php';

/** @var list<array{0: string, 1: callable}> $tests */
$tests = [];

function test(string $name, callable $fn): void
{
    global $tests;
    $tests[] = [$name, $fn];
}

function assertTrue(bool $condition, string $message = 'Expected condition to be true'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message !== '' ? $message . ': ' : '')
            . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true),
        );
    }
}

/** @param class-string<Throwable> $class */
function assertThrows(string $class, callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return;
        }
        throw new RuntimeException('Expected ' . $class . ', got ' . $e::class . ': ' . $e->getMessage());
    }

    throw new RuntimeException('Expected ' . $class . ', but nothing was thrown');
}

/** @param list<string> $fields */
function assertValidation(callable $fn, array $fields): void
{
    try {
        $fn();
    } catch (ValidationException $e) {
        foreach ($fields as $field) {
            assertTrue(array_key_exists($field, $e->errors()), "Missing validation error for {$field}");
        }

        return;
    }

    throw new RuntimeException('Expected ValidationException, but nothing was thrown');
}

test('SignupRequest normalizes valid input', function (): void {
    $request = SignupRequest::fromArray([
        'company_name' => '  Acme Corp  ',
        'email' => '  ACME@Example.COM ',
        'password' => 'long-enough-password',
    ]);

    assertSame('Acme Corp', $request->companyName);
    assertSame('acme@example.com', $request->email);
    assertSame('long-enough-password', $request->password);
});

test('SignupRequest rejects invalid fields', function (): void {
    assertValidation(
        static fn () => SignupRequest::fromArray(['company_name' => 'A', 'email' => 'nope', 'password' => 'short']),
        ['company_name', 'email', 'password'],
    );
});

test('LoginRequest normalizes email', function (): void {
    $request = LoginRequest::fromArray(['email' => ' USER@Example.com ', 'password' => 'secret']);
    assertSame('user@example.com', $request->email);
});

test('LoginRequest rejects missing fields', function (): void {
    assertValidation(static fn () => LoginRequest::fromArray([]), ['email', 'password']);
});

test('SubmitReviewRequest ignores untrusted company_id', function (): void {
    $request = SubmitReviewRequest::fromArray([
        'company_id' => 999,
        'text' => '  Great support  ',
        'rating' => '4',
    ], 7);

    assertSame(7, $request->companyId);
    assertSame('Great support', $request->text);
    assertSame(4.0, $request->rating);
    assertSame('api', $request->source);
});

test('SubmitReviewRequest rejects invalid fields', function (): void {
    assertValidation(
        static fn () => SubmitReviewRequest::fromArray([
            'text' => str_repeat('x', 5001),
            'rating' => 9,
            'source' => 'Not Valid!',
            'source_url' => 'not-a-url',
        ], 1),
        ['text', 'rating', 'source', 'source_url'],
    );
});

test('SubmitReviewRequest truncates external_id', function (): void {
    $request = SubmitReviewRequest::fromArray(['text' => 'ok', 'external_id' => str_repeat('a', 250)], 1);
    assertSame(191, mb_strlen((string) $request->externalId));
});

test('ReviewAnalysis accepts and clamps model output', function (): void {
    $analysis = ReviewAnalysis::fromArray([
        'sentiment' => ' NEGATIVE ',
        'score' => 5,
        'categories' => [' Support ', 'support', '', 'Reliability'],
        'summary' => '  Customers are frustrated.  ',
        'requires_action' => 'true',
    ]);

    assertSame('negative', $analysis->sentiment->value);
    assertSame(1.0, $analysis->score);
    assertSame(['support', 'reliability'], $analysis->categories);
    assertSame('Customers are frustrated.', $analysis->summary);
    assertTrue($analysis->requiresAction);
});

test('ReviewAnalysis rejects malformed model output', function (): void {
    assertThrows(
        AnalysisException::class,
        static fn () => ReviewAnalysis::fromArray([
            'sentiment' => 'angry',
            'score' => 0,
            'categories' => [],
            'summary' => 'x',
            'requires_action' => false,
        ]),
    );
    assertThrows(
        AnalysisException::class,
        static fn () => ReviewAnalysis::fromArray([
            'sentiment' => 'neutral',
            'score' => 'high',
            'categories' => [],
            'summary' => 'x',
            'requires_action' => false,
        ]),
    );
});

test('ReviewAnalysis limits categories and summary length', function (): void {
    $analysis = ReviewAnalysis::fromArray([
        'sentiment' => 'positive',
        'score' => 0.5,
        'categories' => array_map(static fn (int $i): string => "topic-{$i}", range(1, 20)),
        'summary' => str_repeat('a', 600),
        'requires_action' => false,
    ]);

    assertSame(ReviewAnalysis::MAX_CATEGORIES, count($analysis->categories));
    assertSame(ReviewAnalysis::MAX_SUMMARY_LENGTH, mb_strlen($analysis->summary));
});

test('App pages no longer mention subscription plans or monthly usage quotas', function (): void {
    $pages = [
        'signup.html',
        'overview.html',
        'reviews.html',
        'themes.html',
        'alerts.html',
        'reports.html',
        'sources.html',
        'settings.html',
    ];

    foreach ($pages as $page) {
        $html = file_get_contents(ROOT . '/' . $page);
        assertTrue($html !== false, "Unable to read {$page}");
        assertTrue(!str_contains($html, 'Free plan'), "Unexpected plan wording in {$page}");
        assertTrue(!str_contains($html, '100 reviews each month'), "Unexpected monthly quota copy in {$page}");
    }
});

test('Jwt round-trips, rejects tampering and expiry', function (): void {
    $token = Jwt::encode(['company_id' => 42]);
    $claims = Jwt::decode($token);
    assertTrue(is_array($claims), 'Decoded claims should be an array');
    assertSame(42, $claims['company_id']);

    $tampered = substr($token, 0, -1) . ($token[strlen($token) - 1] === 'a' ? 'b' : 'a');
    assertSame(null, Jwt::decode($tampered));

    $expired = Jwt::encode(['company_id' => 42, 'exp' => time() - 10]);
    assertSame(null, Jwt::decode($expired));
});

test('RateLimiter enforces per-identity limits', function (): void {
    $scope = 'test-' . bin2hex(random_bytes(4));
    RateLimiter::enforce($scope, 'first', 2, 3600);
    RateLimiter::enforce($scope, 'first', 2, 3600);
    assertThrows(RateLimitException::class, static fn () => RateLimiter::enforce($scope, 'first', 2, 3600));
    RateLimiter::enforce($scope, 'second', 2, 3600);
});

test('RateLimiter keeps long windows active beyond one hour', function (): void {
    $scope = 'test-' . bin2hex(random_bytes(4));
    $path = sys_get_temp_dir() . '/reviewradar-rate-' . hash('sha256', $scope) . '.json';
    $identity = hash('sha256', 'long-window');

    file_put_contents($path, json_encode([
        $identity => ['started_at' => time() - 7200, 'count' => 3, 'window' => 86400],
    ], JSON_THROW_ON_ERROR));
    assertThrows(RateLimitException::class, static fn () => RateLimiter::enforce($scope, 'long-window', 3, 86400));

    file_put_contents($path, json_encode([
        $identity => ['started_at' => time() - 90000, 'count' => 3, 'window' => 86400],
    ], JSON_THROW_ON_ERROR));
    RateLimiter::enforce($scope, 'long-window', 3, 86400);

    @unlink($path);
});

function writeZipFixture(string $path, array $entries): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create ZIP fixture.');
    }
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
}

/** @param list<string> $lines */
function minimalPdf(array $lines): string
{
    $content = "BT\n/F1 12 Tf\n72 720 Td\n";
    foreach ($lines as $index => $line) {
        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
        $content .= ($index > 0 ? "0 -20 Td\n" : '') . "({$escaped}) Tj\n";
    }
    $content .= 'ET';

    $objects = [
        1 => '<< /Type /Catalog /Pages 2 0 R >>',
        2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        5 => '<< /Length ' . strlen($content) . " >>\nstream\n{$content}\nendstream",
    ];

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $number => $body) {
        $offsets[$number] = strlen($pdf);
        $pdf .= "{$number} 0 obj\n{$body}\nendobj\n";
    }
    $xrefOffset = strlen($pdf);
    $pdf .= 'xref' . "\n" . '0 ' . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($number = 1; $number <= count($objects); $number++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$number]);
    }
    $pdf .= 'trailer' . "\n" . '<< /Size ' . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

    return $pdf;
}

/** @return list<int> */
function freePorts(int $count): array
{
    $sockets = [];
    $ports = [];
    for ($i = 0; $i < $count; $i++) {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            foreach ($sockets as $open) {
                fclose($open);
            }
            throw new RuntimeException("Unable to allocate test ports: {$errstr}");
        }
        $sockets[] = $socket;
        $name = (string) stream_socket_get_name($socket, false);
        $ports[] = (int) substr(strrchr($name, ':'), 1);
    }
    foreach ($sockets as $socket) {
        fclose($socket);
    }

    return $ports;
}

/** @param list<string> $command */
function startProcess(array $command, array $env, string $logPath)
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $logPath, 'a'],
        2 => ['file', $logPath, 'a'],
    ];
    $process = proc_open($command, $descriptors, $pipes, ROOT, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start test server: ' . implode(' ', $command));
    }
    fclose($pipes[0]);

    return $process;
}

function stopProcess($process): void
{
    if (!is_resource($process)) {
        return;
    }
    proc_terminate($process);
    proc_close($process);
}

function waitForServer(string $url, string $logPath): void
{
    $deadline = microtime(true) + 15;
    do {
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => 500,
        ]);
        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($status > 0) {
            return;
        }
        usleep(100_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException(
        "Server did not start at {$url}\n" . (is_file($logPath) ? (string) file_get_contents($logPath) : ''),
    );
}

/**
 * @param array{
 *     json?: array<string, mixed>,
 *     body?: string,
 *     api_key?: string,
 *     jar?: bool,
 *     multipart?: array{name: string, content: string, type?: string}
 * } $options
 * @return array{status: int, raw: string, json: ?array<string, mixed>}
 */
function http(string $method, string $url, array $options = []): array
{
    $cookieJar = $GLOBALS['rr_cookie_jar'];
    $headers = ['Accept: application/json'];
    $body = $options['body'] ?? null;
    $tempFile = null;
    if (isset($options['json'])) {
        $body = json_encode($options['json'], JSON_THROW_ON_ERROR);
        $headers[] = 'Content-Type: application/json';
    }
    if (isset($options['multipart'])) {
        $tempFile = (string) tempnam(sys_get_temp_dir(), 'rr-upload-');
        file_put_contents($tempFile, $options['multipart']['content']);
        $body = [
            'file' => new CURLFile(
                $tempFile,
                $options['multipart']['type'] ?? 'text/csv',
                $options['multipart']['name'],
            ),
        ];
    }
    if (isset($options['api_key'])) {
        $headers[] = 'X-API-Key: ' . $options['api_key'];
    }

    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 120,
    ]);
    if ($options['jar'] ?? true) {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $cookieJar);
    }
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if (is_string($tempFile)) {
        @unlink($tempFile);
    }

    if ($raw === false) {
        throw new RuntimeException('HTTP request failed: ' . $error);
    }

    $decoded = json_decode($raw, true);

    return [
        'status' => $status,
        'raw' => $raw,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

test('ReviewImportParser reads CSV aliases and quoted fields', function (): void {
    $csv = "\xEF\xBB\xBFreview,author,stars,link,id,ignored\n"
        . "\"Broken, again\",Jane,1,https://example.test/r/1,g2-1,ignored\n"
        . "\n"
        . "Great app,Bob,5,,g2-2,ignored\n";

    $rows = ReviewImportParser::parse($csv, 'reviews.csv');
    assertSame(2, count($rows));
    assertSame('Broken, again', $rows[0]['text']);
    assertSame('Jane', $rows[0]['author_name']);
    assertSame('1', $rows[0]['rating']);
    assertSame('https://example.test/r/1', $rows[0]['source_url']);
    assertSame('g2-1', $rows[0]['external_id']);
    assertSame('Great app', $rows[1]['text']);
});

test('ReviewImportParser reads JSON lists and wrapped payloads', function (): void {
    $rows = ReviewImportParser::parse(
        '[{"review_text":"Nice","rating":5,"reviewer":"Ann"}]',
        'reviews.json',
    );
    assertSame('Nice', $rows[0]['text']);
    assertSame('Ann', $rows[0]['author_name']);

    $rows = ReviewImportParser::fromPayload([
        'reviews' => [['text' => 'Second', 'external_id' => 'x-1']],
    ]);
    assertSame('Second', $rows[0]['text']);
    assertSame('x-1', $rows[0]['external_id']);
});

test('ReviewImportParser rejects invalid and oversized files', function (): void {
    assertThrows(ValidationException::class, static fn () => ReviewImportParser::parse('{not json', 'reviews.json'));
    assertThrows(
        ValidationException::class,
        static fn () => ReviewImportParser::fromPayload([
            'reviews' => array_fill(0, ReviewImportParser::MAX_ROWS + 1, ['text' => 'ok']),
        ]),
    );
});

test('ReviewImportParser reads TSV and plain-text reviews', function (): void {
    $tsv = "Review\tAuthor\tStars\nGreat app\tAda\t5\nSlow support\tBob\t2\n";
    $rows = ReviewImportParser::parse($tsv, 'reviews.tsv');
    assertSame(2, count($rows));
    assertSame('Great app', $rows[0]['text']);
    assertSame('Ada', $rows[0]['author_name']);
    assertSame('2', $rows[1]['rating']);

    $text = "The checkout was quick and the product arrived early.\n\n"
        . "Support took three days to answer and never fixed my issue.\n";
    $rows = ReviewImportParser::parse($text, 'reviews.txt');
    assertSame(2, count($rows));
    assertSame('txt', $rows[0]['source']);
    assertTrue(str_contains((string) $rows[1]['text'], 'Support took three days'));
});

test('ReviewImportParser reads HTML tables', function (): void {
    $html = '<html><body><h1>Customer reviews</h1><table>'
        . '<tr><th>Review</th><th>Author</th><th>Stars</th></tr>'
        . '<tr><td>Great app</td><td>Ada</td><td>5</td></tr>'
        . '<tr><td>Slow support</td><td>Bob</td><td>2</td></tr>'
        . '</table></body></html>';
    $rows = ReviewImportParser::parse($html, 'reviews.html');
    assertSame(2, count($rows));
    assertSame('Great app', $rows[0]['text']);
    assertSame('Ada', $rows[0]['author_name']);
    assertSame('2', $rows[1]['rating']);
});

test('ReviewImportParser reads XLSX and DOCX exports', function (): void {
    $dir = sys_get_temp_dir() . '/reviewradar-parser-' . bin2hex(random_bytes(4));
    if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create parser fixture directory.');
    }

    try {
        $xlsx = $dir . '/reviews.xlsx';
        writeZipFixture($xlsx, [
            'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets/></workbook>',
            'xl/worksheets/sheet1.xml' => <<<'XML'
                <worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>
                <row r="1"><c r="A1" t="inlineStr"><is><t>Review</t></is></c><c r="B1" t="inlineStr"><is><t>Stars</t></is></c></row>
                <row r="2"><c r="A2" t="inlineStr"><is><t>Great app</t></is></c><c r="B2"><v>5</v></c></row>
                <row r="3"><c r="A3" t="inlineStr"><is><t>Slow support</t></is></c><c r="B3"><v>2</v></c></row>
                </sheetData></worksheet>
                XML,
        ]);
        $rows = ReviewImportParser::parse((string) file_get_contents($xlsx), 'reviews.xlsx');
        assertSame(2, count($rows));
        assertSame('Great app', $rows[0]['text']);
        assertSame('2', $rows[1]['rating']);

        $docx = $dir . '/reviews.docx';
        writeZipFixture($docx, [
            'word/document.xml' => <<<'XML'
                <w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:tbl>
                <w:tr><w:tc><w:p><w:r><w:t>Review</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Author</w:t></w:r></w:p></w:tc></w:tr>
                <w:tr><w:tc><w:p><w:r><w:t>Excellent onboarding</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Ann</w:t></w:r></w:p></w:tc></w:tr>
                <w:tr><w:tc><w:p><w:r><w:t>Billing was confusing</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Ben</w:t></w:r></w:p></w:tc></w:tr>
                </w:tbl></w:body></w:document>
                XML,
        ]);
        $rows = ReviewImportParser::parse((string) file_get_contents($docx), 'reviews.docx');
        assertSame(2, count($rows));
        assertSame('Excellent onboarding', $rows[0]['text']);
        assertSame('Ben', $rows[1]['author_name']);
    } finally {
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
});

test('ReviewImportParser extracts text-based PDFs', function (): void {
    if (!is_executable('/usr/bin/pdftotext') && getenv('PDFTOTEXT_BIN') === false) {
        return;
    }

    $pdf = minimalPdf([
        'Great service and fast shipping.',
        'Support was slow but eventually helped.',
    ]);
    $rows = ReviewImportParser::parse($pdf, 'reviews.pdf');
    assertSame(2, count($rows));
    assertTrue(str_contains((string) $rows[0]['text'], 'Great service'));
    assertTrue(str_contains((string) $rows[1]['text'], 'Support was slow'));
});

test('HTTP end-to-end flow', function (): void {
    $tempDir = sys_get_temp_dir() . '/reviewradar-tests-' . bin2hex(random_bytes(6));
    $runtimeDir = $tempDir . '/runtime';
    if (!mkdir($runtimeDir, 0700, true) && !is_dir($runtimeDir)) {
        throw new RuntimeException('Unable to create test runtime directory.');
    }
    $GLOBALS['rr_cookie_jar'] = $tempDir . '/cookies.txt';
    $appLog = $tempDir . '/app.log';
    $mockLog = $tempDir . '/mock.log';

    [$mockPort, $appPort] = freePorts(2);
    $baseEnv = getenv();
    $env = array_merge($baseEnv, [
        'TMPDIR' => $runtimeDir,
        'APP_ENV' => 'local',
        'GROQ_ENDPOINT' => "http://127.0.0.1:{$mockPort}/v1/chat/completions",
        'GROQ_MODEL' => 'mock-review-model',
        'GROQ_API_KEY' => 'test-key',
    ]);

    $mock = null;
    $app = null;
    $email = null;

    try {
        $mock = startProcess([PHP_BINARY, '-S', "127.0.0.1:{$mockPort}", ROOT . '/tests/mock_groq.php'], $env, $mockLog);
        waitForServer("http://127.0.0.1:{$mockPort}/v1/models", $mockLog);

        $app = startProcess(
            [
                PHP_BINARY,
                '-d', 'upload_max_filesize=20M',
                '-d', 'post_max_size=25M',
                '-S', "127.0.0.1:{$appPort}",
                '-t', ROOT,
                ROOT . '/router.php',
            ],
            $env,
            $appLog,
        );
        waitForServer("http://127.0.0.1:{$appPort}/", $appLog);

        $base = "http://127.0.0.1:{$appPort}";

        $response = http('GET', $base . '/');
        assertSame(200, $response['status'], 'Home page');

        foreach (['/.env', '/src/Support/Config.php', '/config/config.php', '/tests/run.php', '/scripts/import_reviews.php', '/.git/config'] as $path) {
            $response = http('GET', $base . $path, ['jar' => false]);
            assertSame(404, $response['status'], "Blocked path {$path}");
        }

        $response = http('GET', $base . '/api/get_analytics.php', ['jar' => false]);
        assertSame(401, $response['status'], 'Unauthenticated analytics');
        $response = http('POST', $base . '/api/import_reviews.php', [
            'json' => ['reviews' => [['text' => 'anonymous import']]],
            'jar' => false,
        ]);
        assertSame(401, $response['status'], 'Unauthenticated import');
        $response = http('POST', $base . '/api/parse_import.php', [
            'multipart' => ['name' => 'reviews.csv', 'content' => "review\nAnonymous\n", 'type' => 'text/csv'],
            'jar' => false,
        ]);
        assertSame(401, $response['status'], 'Unauthenticated parse');

        $email = 'test-' . bin2hex(random_bytes(6)) . '@example.test';
        $companyName = 'Test Company ' . bin2hex(random_bytes(3));
        $password = 'test-password-123';

        $response = http('POST', $base . '/api/signup.php', [
            'json' => ['company_name' => $companyName, 'email' => $email, 'password' => $password],
        ]);
        assertSame(201, $response['status'], 'Signup');
        assertTrue(isset($response['json']['data']['company']['email']), 'Signup response should include the company profile');

        $response = http('GET', $base . '/api/get_analytics.php');
        assertSame(200, $response['status'], 'Analytics after signup');
        assertSame(0, $response['json']['data']['totals']['reviews'] ?? null);

        $reviewText = 'The export crashed twice and support never replied.';
        $response = http('POST', $base . '/api/submit_review.php', [
            'json' => ['text' => $reviewText, 'rating' => 1, 'source' => 'manual'],
        ]);
        assertSame(201, $response['status'], 'Submit review');
        assertSame('negative', $response['json']['data']['analysis']['sentiment'] ?? null);
        assertSame(true, $response['json']['data']['analysis']['requires_action'] ?? null);

        $response = http('GET', $base . '/api/get_reviews.php');
        assertSame(200, $response['status'], 'List reviews');
        assertSame(1, $response['json']['data']['count'] ?? null);
        assertSame($reviewText, $response['json']['data']['reviews'][0]['raw_text'] ?? null);

        $response = http('GET', $base . '/api/export_reviews.php');
        assertSame(200, $response['status'], 'Export reviews');
        assertTrue(str_contains($response['raw'], $reviewText), 'CSV should contain the review text');

        $response = http('POST', $base . '/api/import_reviews.php', [
            'json' => ['reviews' => array_fill(0, 26, ['text' => 'Too many for one batch'])],
        ]);
        assertSame(422, $response['status'], 'Import rejects oversized batches');

        $response = http('POST', $base . '/api/import_reviews.php', [
            'json' => ['reviews' => [
                ['text' => 'Love the new dashboard and fast checkout.', 'rating' => 5, 'source' => 'g2', 'external_id' => 'http-1'],
                ['text' => 'The export crashed again and support never replied.', 'rating' => 1, 'source' => 'trustpilot', 'external_id' => 'http-2'],
                ['text' => '', 'rating' => 3],
            ]],
        ]);
        assertSame(200, $response['status'], 'Import JSON reviews');
        assertSame(3, $response['json']['data']['total'] ?? null);
        assertSame(2, $response['json']['data']['imported'] ?? null);
        assertSame(1, $response['json']['data']['failed'] ?? null);
        assertSame(3, $response['json']['data']['errors'][0]['row'] ?? null);

        $csv = "review,author,rating,source,external_id\n"
            . "\"Quoted, with comma\",Jane,4,web,http-3\n";
        $response = http('POST', $base . '/api/parse_import.php', [
            'multipart' => [
                'name' => 'reviews.tsv',
                'content' => "review\tauthor\nParsed row\tAda\n",
                'type' => 'text/tab-separated-values',
            ],
        ]);
        assertSame(200, $response['status'], 'Parse TSV upload');
        assertSame(1, $response['json']['data']['count'] ?? null);
        assertSame('Parsed row', $response['json']['data']['rows'][0]['text'] ?? null);
        assertSame('Ada', $response['json']['data']['rows'][0]['author_name'] ?? null);

        $response = http('POST', $base . '/api/import_reviews.php', [
            'multipart' => ['name' => 'reviews.csv', 'content' => $csv, 'type' => 'text/csv'],
        ]);
        assertSame(200, $response['status'], 'Import CSV upload');
        assertSame(1, $response['json']['data']['imported'] ?? null);

        $response = http('POST', $base . '/api/import_reviews.php', [
            'multipart' => ['name' => 'reviews.csv', 'content' => $csv, 'type' => 'text/csv'],
        ]);
        assertSame(200, $response['status'], 'Duplicate CSV import');
        assertSame(0, $response['json']['data']['imported'] ?? null);
        assertSame(1, $response['json']['data']['failed'] ?? null);

        $response = http('GET', $base . '/api/get_reviews.php');
        assertSame(200, $response['status'], 'List after import');
        assertSame(4, $response['json']['data']['count'] ?? null);

        $response = http('GET', $base . '/api/get_api_key.php');
        assertSame(200, $response['status'], 'Reveal API key');
        $firstKey = $response['json']['data']['api_key'] ?? '';
        assertSame(64, strlen((string) $firstKey));
        assertTrue(ctype_xdigit((string) $firstKey), 'API key should be hex');

        $response = http('POST', $base . '/api/rotate_api_key.php', ['json' => []]);
        assertSame(200, $response['status'], 'Rotate API key');
        $secondKey = $response['json']['data']['api_key'] ?? '';
        assertTrue($secondKey !== $firstKey, 'Rotated key should differ');

        $response = http('GET', $base . '/api/get_reviews.php', ['jar' => false, 'api_key' => (string) $secondKey]);
        assertSame(200, $response['status'], 'Authenticate with rotated API key');
        $response = http('GET', $base . '/api/get_reviews.php', ['jar' => false, 'api_key' => (string) $firstKey]);
        assertSame(401, $response['status'], 'Old API key rejected');

        $response = http('POST', $base . '/api/logout.php', ['json' => []]);
        assertSame(200, $response['status'], 'Logout');
        $response = http('GET', $base . '/api/get_analytics.php');
        assertSame(401, $response['status'], 'Analytics after logout');

        $response = http('POST', $base . '/api/login.php', [
            'json' => ['email' => $email, 'password' => $password],
        ]);
        assertSame(200, $response['status'], 'Login');
        $response = http('GET', $base . '/api/get_analytics.php');
        assertSame(200, $response['status'], 'Analytics after login');

        $response = http('POST', $base . '/api/delete_account.php', [
            'json' => ['company_name' => $companyName, 'password' => 'wrong-password'],
        ]);
        assertSame(401, $response['status'], 'Delete rejects wrong password');

        $response = http('POST', $base . '/api/delete_account.php', [
            'json' => ['company_name' => $companyName, 'password' => $password],
        ]);
        assertSame(200, $response['status'], 'Delete workspace');
        $email = null;

        $response = http('GET', $base . '/api/get_analytics.php');
        assertSame(401, $response['status'], 'Analytics after deletion');
    } finally {
        if (is_string($email)) {
            try {
                $pdo = ReviewRadar\Support\Database::pdo();
                $find = $pdo->prepare('SELECT id FROM companies WHERE email = :email');
                $find->execute(['email' => $email]);
                $companyId = $find->fetchColumn();
                if ($companyId !== false) {
                    $delete = $pdo->prepare('DELETE FROM companies WHERE id = :id');
                    $delete->execute(['id' => $companyId]);
                }
            } catch (Throwable) {
                // Best-effort cleanup; the test result is reported below.
            }
        }

        stopProcess($app);
        stopProcess($mock);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        @rmdir($tempDir);
    }
});

if (!Config::string('app.jwt_secret')) {
    fwrite(STDERR, "JWT_SECRET is not configured; JWT tests will fail.\n");
}

$failures = 0;
$started = microtime(true);
foreach ($tests as [$name, $fn]) {
    try {
        $fn();
        echo "ok - {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL - {$name}: {$e->getMessage()}\n";
    }
}

$duration = round(microtime(true) - $started, 2);
$total = count($tests);
echo "\n" . ($total - $failures) . "/{$total} tests passed in {$duration}s\n";

exit($failures === 0 ? 0 : 1);
