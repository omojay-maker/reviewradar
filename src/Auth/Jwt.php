<?php

declare(strict_types=1);

namespace ReviewRadar\Auth;

use JsonException;
use ReviewRadar\Support\Config;

/**
 * Minimal HS256 JWT implementation (encode/decode) - no external dependencies.
 */
final class Jwt
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $claims e.g. ['company_id' => 1]
     */
    public static function encode(array $claims): string
    {
        $secret = self::secret();
        if ($secret === '') {
            throw new \RuntimeException('JWT_SECRET is not configured.');
        }

        $claims['iat'] = $claims['iat'] ?? time();
        $claims['exp'] = $claims['exp'] ?? time() + Config::int('app.jwt_ttl_seconds', 3600);

        $header = self::base64UrlEncode(self::jsonEncode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::base64UrlEncode(self::jsonEncode($claims));
        $signature = self::base64UrlEncode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));

        return "{$header}.{$payload}.{$signature}";
    }

    /**
     * @return array<string, mixed>|null Claims when signature and expiry are valid.
     */
    public static function decode(string $token): ?array
    {
        $secret = self::secret();
        if ($secret === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;
        $expected = self::base64UrlEncode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        try {
            $claims = json_decode(self::base64UrlDecode($payload), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($claims)) {
            return null;
        }
        if (isset($claims['exp']) && (int) $claims['exp'] < time()) {
            return null;
        }

        return $claims;
    }

    private static function secret(): string
    {
        return Config::string('app.jwt_secret', '');
    }

    /** @param array<string, mixed> $data */
    private static function jsonEncode(array $data): string
    {
        try {
            return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \RuntimeException('Failed to encode JWT payload.', 0, $e);
        }
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}
