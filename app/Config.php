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

        // Support both local project .env and parent workspace .env
        // (common in this repo where app lives in nested folder).
        $envCandidates = [
            __DIR__ . '/../.env',
            dirname(__DIR__, 2) . '/.env',
        ];
        foreach ($envCandidates as $envFile) {
            if (!is_file($envFile) || !is_readable($envFile)) {
                continue;
            }
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (count($parts) !== 2) {
                    continue;
                }
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                // Keep first value found (project-local has precedence over parent .env).
                if ($key !== '' && !array_key_exists($key, self::$config)) {
                    self::$config[$key] = trim($value, " \t\n\r\0\x0B\"'");
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


