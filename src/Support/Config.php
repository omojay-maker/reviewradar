<?php

declare(strict_types=1);

namespace ReviewRadar\Support;

use InvalidArgumentException;

final class Config
{
    /** @var array<string, mixed> */
    private static array $values = [];

    private function __construct()
    {
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function load(string $path): void
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException("Config file not found: {$path}");
        }

        $config = require $path;
        if (!is_array($config)) {
            throw new InvalidArgumentException("Config file must return an array: {$path}");
        }

        self::$values = $config;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$values;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function string(string $key, string $default = ''): string
    {
        $value = self::get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
