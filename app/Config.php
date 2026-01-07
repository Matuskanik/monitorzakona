<?php

namespace App;

class Config
{
    private static array $config = [];

    public static function load(): void
    {
        $envFile = __DIR__ . '/../.env';
        if (!file_exists($envFile)) {
            throw new \RuntimeException('.env file not found. Please copy .env.example to .env');
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            self::$config[trim($key)] = trim($value);
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$config[$key] ?? $default;
    }

    public static function all(): array
    {
        return self::$config;
    }
}


