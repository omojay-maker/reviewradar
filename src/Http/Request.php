<?php

declare(strict_types=1);

namespace ReviewRadar\Http;

use ReviewRadar\Http\JsonResponse;

final class Request
{
    private function __construct()
    {
    }

    /**
     * Decode the request body: JSON by default, with a form-encoding fallback
     * so plain HTML forms (no-JS) POST meaningful payloads.
     *
     * @return array<string, mixed>
     */
    public static function jsonBody(): array
    {
        $contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            return $_POST;
        }

        $raw = file_get_contents('php://input');
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($payload)) {
            JsonResponse::error('Request body must be a valid JSON object.', 400);
        }

        return $payload;
    }
}
