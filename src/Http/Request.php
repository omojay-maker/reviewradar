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
     * Decode the raw JSON request body.
     *
     * @return array<string, mixed>
     */
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        $payload = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($payload)) {
            JsonResponse::error('Request body must be a valid JSON object.', 400);
        }

        return $payload;
    }
}
