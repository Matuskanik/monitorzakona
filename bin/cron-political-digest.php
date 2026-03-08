#!/usr/bin/env php
<?php

/**
 * Generuje politický digest (dnes, tento týždeň, tento mesiac).
 * Web search + 2x kontrola kvality. Prepojenie s poslancami.
 *
 * Usage: php bin/cron-political-digest.php [--force]
 *   --force = vygenerovať aj keď už existuje digest na dnes
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\Logger;
use App\PoliticalDigestGenerator;

try {
    Config::load();
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

$apiKey = Config::get('OPENAI_API_KEY') ?: getenv('OPENAI_API_KEY');
if (empty($apiKey) || $apiKey === 'your_openai_api_key_here') {
    echo "OPENAI_API_KEY nie je nastavený.\n";
    exit(1);
}

$logger = new Logger(Config::get('LOG_PATH', 'storage/logs') . '/app.log');
$db = new Database(Config::get('DB_PATH', 'data/sentinel.db'));
$force = in_array('--force', $argv ?? [], true);

$today = date('Y-m-d');
$existing = $db->getLatestPoliticalDigest();
if (!$force && $existing && ($existing['digest_date'] ?? '') === $today) {
    echo "Digest na {$today} už existuje. Použite --force pre regeneráciu.\n";
    exit(0);
}

$generator = new PoliticalDigestGenerator($apiKey, $logger, $db);

try {
    echo "Generujem politický digest ({$today})...\n";
    $digest = $generator->generate();
    $db->savePoliticalDigest($today, $digest);
    echo "OK – digest uložený.\n";
    $logger->info("Political digest generated for {$today}");
} catch (\Throwable $e) {
    echo "CHYBA: " . $e->getMessage() . "\n";
    $logger->error("Political digest failed: " . $e->getMessage());
    exit(1);
}
