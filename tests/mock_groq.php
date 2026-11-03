<?php

declare(strict_types=1);

/**
 * Minimal Groq chat-completions mock used by tests/run.php.
 *
 * Usage: php -S 127.0.0.1:PORT tests/mock_groq.php
 */

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($path === '/v1/models') {
    header('Content-Type: application/json');
    echo json_encode([
        'object' => 'list',
        'data' => [['id' => 'mock-review-model', 'object' => 'model']],
    ]);
    exit;
}

if ($path !== '/v1/chat/completions' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => ['message' => 'Not found']]);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
$text = '';
foreach (($payload['messages'] ?? []) as $message) {
    if (($message['role'] ?? '') === 'user') {
        $text = strtolower((string) ($message['content'] ?? ''));
    }
}

$negative = preg_match('/crash|cancel|unacceptable|terrible|never replied|broken/', $text) === 1;
$positive = preg_match('/great|love|excellent|easy|happy/', $text) === 1;

$analysis = [
    'sentiment' => $negative ? 'negative' : ($positive ? 'positive' : 'neutral'),
    'score' => $negative ? -0.9 : ($positive ? 0.9 : 0.0),
    'categories' => $negative ? ['support', 'reliability'] : ['product quality'],
    'summary' => $negative
        ? 'The customer reported a serious product failure and poor support.'
        : 'The customer described a positive product experience.',
    'requires_action' => $negative,
];

header('Content-Type: application/json');
echo json_encode([
    'id' => 'chatcmpl-mock',
    'object' => 'chat.completion',
    'model' => (string) ($payload['model'] ?? 'mock-review-model'),
    'choices' => [[
        'index' => 0,
        'message' => ['role' => 'assistant', 'content' => json_encode($analysis)],
        'finish_reason' => 'stop',
    ]],
]);
