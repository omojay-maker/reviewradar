<?php

declare(strict_types=1);

namespace ReviewRadar\Http;

final class JsonResponse
{
    private function __construct()
    {
    }

    /**
     * Emit a JSON payload and terminate the request.
     *
     * @param array<string, mixed> $payload
     */
    public static function send(array $payload, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function success(array $data, int $status = 200): never
    {
        self::send(['success' => true, 'data' => $data], $status);
    }

    /**
     * @param array<string, string> $fieldErrors
     */
    public static function error(string $message, int $status, array $fieldErrors = []): never
    {
        $error = ['message' => $message];
        if ($fieldErrors !== []) {
            $error['fields'] = $fieldErrors;
        }

        self::send(['success' => false, 'error' => $error], $status);
    }
}
