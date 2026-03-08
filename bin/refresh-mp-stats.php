#!/usr/bin/env php
<?php

/**
 * Refresh stats and card for all MPs from parliament_votes.
 * Fixes stale attendance_pct and total_votes when new voting details were fetched.
 *
 * Usage: php bin/refresh-mp-stats.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\Logger;
use App\ParliamentAnalyzer;

try {
    Config::load();
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

$logger = new Logger(Config::get('LOG_PATH', 'storage/logs') . '/app.log');
$db = new Database(Config::get('DB_PATH', 'data/sentinel.db'));
$analyzer = new ParliamentAnalyzer();

$mps = $db->getAllParliamentMpsForMosaic();
$refreshed = 0;

foreach ($mps as $mp) {
    $mpId = (int)$mp['id'];
    $db->refreshParliamentMpStatsFromVotes($mpId, $analyzer);
    $refreshed++;
}

echo "Obnovené štatistiky pre {$refreshed} poslancov.\n";
