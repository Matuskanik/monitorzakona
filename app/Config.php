<?php

namespace App;

class Config
{
    private static array $config = [];
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        $envFile = __DIR__ . '/../.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    self::$config[trim($parts[0])] = trim($parts[1]);
                }
            }
        }
        // On Digital Ocean / Heroku etc. env vars are set in the dashboard, no .env file

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = self::$config[$key] ?? null;
        if ($value !== null && (string) $value !== '') {
            return trim((string) $value);
        }
        $env = getenv($key);
        if ($env !== false && trim((string) $env) !== '') {
            return trim((string) $env);
        }
        $envValue = $_ENV[$key] ?? null;
        if ($envValue !== null && trim((string) $envValue) !== '') {
            return trim((string) $envValue);
        }
        $serverValue = $_SERVER[$key] ?? null;
        if ($serverValue !== null && trim((string) $serverValue) !== '') {
            return trim((string) $serverValue);
        }
        return $default;
    }

    public static function all(): array
    {
        return self::$config;
    }
}


