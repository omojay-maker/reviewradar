<?php

declare(strict_types=1);

namespace ReviewRadar\Support;

use ReviewRadar\Exceptions\RateLimitException;

final class RateLimiter
{
    private function __construct()
    {
    }

    public static function enforce(string $scope, string $identity, int $limit, int $windowSeconds): void
    {
        $scopeKey = hash('sha256', $scope);
        $identityKey = hash('sha256', $identity);
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'reviewradar-rate-' . $scopeKey . '.json';
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Rate limit storage is unavailable.');
        }
        chmod($path, 0600);
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock rate limit storage.');
            }
            rewind($handle);
            $state = json_decode((string) stream_get_contents($handle), true);
            $now = time();
            $entries = is_array($state) ? $state : [];
            foreach ($entries as $key => $entry) {
                $entryWindow = is_array($entry) ? (int) ($entry['window'] ?? $windowSeconds) : $windowSeconds;
                if (!is_array($entry) || $now > (int) ($entry['started_at'] ?? 0) + max(1, $entryWindow)) {
                    unset($entries[$key]);
                }
            }
            $entry = $entries[$identityKey] ?? [];
            $startedAt = (int) ($entry['started_at'] ?? 0);
            $count = (int) ($entry['count'] ?? 0);
            if ($startedAt <= 0 || $now >= $startedAt + $windowSeconds) {
                $startedAt = $now;
                $count = 0;
            }
            if ($count >= $limit) {
                throw new RateLimitException(max(1, $startedAt + $windowSeconds - $now));
            }
            $entries[$identityKey] = [
                'started_at' => $startedAt,
                'count' => $count + 1,
                'window' => $windowSeconds,
            ];
            if (count($entries) > 10000) {
                uasort($entries, static fn (array $a, array $b): int => (int) ($a['started_at'] ?? 0) <=> (int) ($b['started_at'] ?? 0));
                $entries = array_slice($entries, -10000, null, true);
            }
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($entries, JSON_THROW_ON_ERROR));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
