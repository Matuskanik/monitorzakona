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
        if ($value !== null && $value !== '') {
            return $value;
        }
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        // Some platforms (e.g. PHP-FPM) expose env only in $_ENV
        $envValue = $_ENV[$key] ?? null;
        if ($envValue !== null && $envValue !== '') {
            return (string) $envValue;
        }
        return $default;
    }

    public static function all(): array
    {
        return self::$config;
    }
}


