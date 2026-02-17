<?php

namespace App;

/**
 * Maintenance mode – blocks access to the app while in development.
 * Enable via env var MAINTENANCE_MODE=1 on Digital Ocean.
 * Bypass: ?maintenance_bypass=YOUR_SECRET (set MAINTENANCE_BYPASS_SECRET)
 */
class Maintenance
{
    public static function check(): void
    {
        $mode = getenv('MAINTENANCE_MODE') ?: ($_SERVER['MAINTENANCE_MODE'] ?? $_ENV['MAINTENANCE_MODE'] ?? '');
        if ($mode === '' || $mode === '0' || strtolower($mode) === 'false') {
            return;
        }

        // Stripe webhook must stay reachable
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if ($script === 'stripe-webhook.php') {
            return;
        }

        // Allow bypass with secret
        $bypassSecret = getenv('MAINTENANCE_BYPASS_SECRET') ?: ($_SERVER['MAINTENANCE_BYPASS_SECRET'] ?? '');
        if ($bypassSecret !== '' && ($_GET['maintenance_bypass'] ?? '') === $bypassSecret) {
            return;
        }

        self::showMaintenancePage();
    }

    private static function showMaintenancePage(): void
    {
        http_response_code(503);
        header('Retry-After: 86400'); // 24 hours
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>Monitor zákona – údržba</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, sans-serif; margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f5f5f5; color: #333; }
        .box { max-width: 420px; padding: 2rem; background: #fff; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); text-align: center; }
        h1 { margin: 0 0 1rem; font-size: 1.5rem; color: #1a1a1a; }
        p { margin: 0; line-height: 1.6; color: #555; }
    </style>
</head>
<body>
    <div class="box">
        <h1>🔧 Stránka je v údržbe</h1>
        <p>Monitor zákona je momentálne nedostupný. Pracujeme na vylepšeniach a čoskoro budeme späť.</p>
    </div>
</body>
</html>
<?php
        exit;
    }
}
