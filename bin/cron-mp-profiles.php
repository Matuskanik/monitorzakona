#!/usr/bin/env php
<?php

/**
 * Generate AI media profiles for MPs (last month, last year, famous quote, expertise).
 * Uses web search for thorough research in Slovak media.
 *
 * Usage: php bin/cron-mp-profiles.php [limit] [--force]
 *   limit = max MPs to process (0 = all). Default 0 = všetci.
 *   --force = spracovať aj tých s existujúcim profilom (hlbkový refresh).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\Logger;
use App\MpProfileGenerator;

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
$model = Config::get('MP_PROFILE_MODEL', 'gpt-4o-mini-search-preview');
$generator = new MpProfileGenerator($apiKey, $logger, $model);

$args = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$args = array_values(array_filter($args, fn($a) => $a !== '--force'));
$limit = (int)($args[0] ?? 0);
if ($limit <= 0) {
    $limit = 9999;
}

$all = $db->getAllParliamentMpsForMosaic();
$withoutProfile = [];
$withProfile = [];
foreach ($all as $mp) {
    $has = !empty($mp['media_profile_json']);
    $profile = $has ? json_decode($mp['media_profile_json'], true) : [];
    $hasValid = $has && is_array($profile) && !MpProfileGenerator::isPlaceholderContent($profile);
    if (!$hasValid) {
        $withoutProfile[] = $mp;
    } else {
        $withProfile[] = $mp;
    }
}
$mps = $force ? $all : array_merge($withoutProfile, $withProfile);
$mps = array_slice($mps, 0, $limit);

$delaySec = (int)Config::get('MP_PROFILE_DELAY_SECONDS', '3');
$processed = 0;
$failed = 0;

echo "Spracovávam " . count($mps) . " poslancov (model: {$model})...\n";

foreach ($mps as $mp) {
    $mpId = (int)$mp['id'];
    $name = $mp['full_name'] ?? '?';

    if (!$force && !empty($mp['media_profile_json'])) {
        $profile = json_decode($mp['media_profile_json'], true);
        if (is_array($profile) && !MpProfileGenerator::isPlaceholderContent($profile)) {
            echo "  [skip] {$name} – už má profil\n";
            continue;
        }
    }

    try {
        $profile = $generator->generate($mp);
        $db->updateParliamentMpMediaProfile($mpId, $profile);
        $processed++;
        echo "  [ok] {$name}\n";
    } catch (\Throwable $e) {
        $failed++;
        $logger->error('MP profile failed', ['mp' => $name, 'error' => $e->getMessage()]);
        echo "  [err] {$name}: " . $e->getMessage() . "\n";
    }

    if ($delaySec > 0) {
        sleep($delaySec);
    }
}

echo "Hotovo: {$processed} spracovaných, {$failed} chýb.\n";
