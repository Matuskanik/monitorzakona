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
        
        // Try to load from .env file first
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
        
        // Also load from system environment variables (for DigitalOcean, Heroku, etc.)
        // System env vars take precedence over .env file
        $envKeys = [
            'OPENAI_API_KEY', 'OPENAI_MODEL', 'OPENAI_MAX_TOKENS',
            'BASE_URL', 'APP_NAME',
            'NR_SR_LIST_URL', 'REQUEST_DELAY_SECONDS', 'MAX_RETRIES', 'RETRY_DELAY_SECONDS',
            'STORAGE_PATH', 'DATA_PATH', 'LOG_PATH', 'DB_PATH'
        ];
        
        foreach ($envKeys as $key) {
            $value = getenv($key);
            if ($value !== false && $value !== '') {
                self::$config[$key] = $value;
            }
        }
        
        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        // First check our loaded config
        if (isset(self::$config[$key])) {
            return self::$config[$key];
        }
        
        // Fall back to system environment variable
        $envValue = getenv($key);
        if ($envValue !== false && $envValue !== '') {
            return $envValue;
        }
        
        return $default;
    }

    public static function all(): array
    {
        return self::$config;
    }
}


