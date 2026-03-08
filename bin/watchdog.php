#!/usr/bin/env php
<?php

/**
 * Watchdog script - Daily law monitoring and update
 * 
 * This script should be run daily at 00:00 (midnight) to:
 * 1. Fetch the latest laws from NR SR
 * 2. Process new laws that haven't been processed yet
 * 3. Update the database with latest information
 * 
 * Setup cron job:
 * 0 0 * * * cd /path/to/Sentinel && php bin/watchdog.php >> storage/logs/watchdog.log 2>&1
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\Logger;
use App\PoliticalDigestGenerator;
use App\Scraper;
use App\DocumentExtractor;
use App\OpenAIClient;
use App\LawProcessor;

// Try to load config, but fallback to defaults if .env is not accessible
$dbPath = null;
$logPath = null;
$openaiKey = null;
$openaiModel = 'gpt-4o-mini';
$openaiMaxTokens = 2000;
$storagePath = __DIR__ . '/../storage';
$requestDelay = 3;
$maxRetries = 3;
$retryDelay = 5;
$nrSrListUrl = 'https://www.nrsr.sk/web/default.aspx?SectionId=184';
$maxLawsToProcess = 50; // Maximum new laws to process per day

try {
    Config::load();
    $dbPath = Config::get('DB_PATH');
    $logPath = Config::get('LOG_PATH', __DIR__ . '/../storage/logs');
    $openaiKey = Config::get('OPENAI_API_KEY');
    $openaiModel = Config::get('OPENAI_MODEL', 'gpt-4o-mini');
    $openaiMaxTokens = (int)Config::get('OPENAI_MAX_TOKENS', '2000');
    $storagePath = Config::get('STORAGE_PATH', __DIR__ . '/../storage');
    $requestDelay = (int)Config::get('REQUEST_DELAY_SECONDS', '3');
    $maxRetries = (int)Config::get('MAX_RETRIES', '3');
    $retryDelay = (int)Config::get('RETRY_DELAY_SECONDS', '5');
    $nrSrListUrl = Config::get('NR_SR_LIST_URL', 'https://www.nrsr.sk/web/default.aspx?SectionId=184');
    $maxLawsToProcess = (int)Config::get('WATCHDOG_MAX_LAWS', '50');
} catch (\Exception $e) {
    // Fallback to default paths
    $dbPath = __DIR__ . '/../data/sentinel.db';
    if (!file_exists($dbPath)) {
        $dbPath = __DIR__ . '/../public/data/sentinel.db';
    }
    $logPath = __DIR__ . '/../storage/logs';
    
    // Try to get OpenAI key from environment
    $openaiKey = getenv('OPENAI_API_KEY');
    
    if (empty($openaiKey)) {
        error_log("ERROR: OPENAI_API_KEY not found. Please set it in .env file or environment variable.");
        exit(1);
    }
}

$logger = new Logger($logPath . '/app.log');
$watchdogLogger = new Logger($logPath . '/watchdog.log');
$db = new Database($dbPath);

$watchdogLogger->info("=== Watchdog started at " . date('Y-m-d H:i:s') . " ===");

$scraper = new Scraper(
    $logger,
    $requestDelay,
    $maxRetries,
    $retryDelay
);

$extractor = new DocumentExtractor($logger);

$aiClient = new OpenAIClient(
    $openaiKey,
    $openaiModel,
    $openaiMaxTokens,
    $logger
);

$processor = new LawProcessor(
    $scraper,
    $extractor,
    $aiClient,
    $db,
    $logger,
    $storagePath
);

try {
    // Political digest (home page) – run daily
    $today = date('Y-m-d');
    $existingDigest = $db->getLatestPoliticalDigest();
    if (!$existingDigest || ($existingDigest['digest_date'] ?? '') !== $today) {
        try {
            $watchdogLogger->info("Generating political digest for {$today}...");
            $digestGen = new PoliticalDigestGenerator($openaiKey, $logger, $db);
            $digest = $digestGen->generate();
            $db->savePoliticalDigest($today, $digest);
            $watchdogLogger->info("Political digest saved.");
        } catch (\Throwable $e) {
            $watchdogLogger->warning("Political digest failed: " . $e->getMessage());
        }
    }

    // Fetch list page
    $watchdogLogger->info("Fetching laws list from NR SR...");
    $listHtml = $scraper->fetchListPage($nrSrListUrl);
    
    // Save snapshot
    $snapshotDir = $storagePath . '/snapshots';
    if (!is_dir($snapshotDir)) {
        mkdir($snapshotDir, 0755, true);
    }
    file_put_contents($snapshotDir . '/source_list_' . date('Y-m-d_H-i-s') . '.html', $listHtml);
    
    // Parse laws
    $watchdogLogger->info("Parsing laws list...");
    $laws = $scraper->parseListPage($listHtml);
    
    if (empty($laws)) {
        $watchdogLogger->warning("No laws found on list page");
        exit(0);
    }
    
    // Filter out already processed laws
    $newLaws = [];
    foreach ($laws as $law) {
        $existing = $db->findLawByMasterId($law['master_id']);
        if (!$existing) {
            $newLaws[] = $law;
        }
    }
    
    $watchdogLogger->info("Found " . count($laws) . " total laws, " . count($newLaws) . " new laws to process");
    
    // Limit to max laws per day
    $lawsToProcess = array_slice($newLaws, 0, $maxLawsToProcess);
    
    if (empty($lawsToProcess)) {
        $watchdogLogger->info("No new laws to process. All laws are up to date.");
        echo "No new laws to process.\n";
        exit(0);
    }
    
    $watchdogLogger->info("Processing " . count($lawsToProcess) . " new law(s)");
    
    // Process each law
    $processed = 0;
    $skipped = 0;
    $errors = 0;
    $startTime = time();
    
    foreach ($lawsToProcess as $index => $law) {
        $lawNum = $index + 1;
        $total = count($lawsToProcess);
        $watchdogLogger->info("[{$lawNum}/{$total}] Processing: {$law['title']} (MasterID: {$law['master_id']})");
        
        try {
            if ($processor->processLaw($law)) {
                $processed++;
                $watchdogLogger->info("  ✓ Success");
            } else {
                $skipped++;
                $watchdogLogger->warning("  ⊘ Skipped");
            }
        } catch (\Exception $e) {
            $errors++;
            $watchdogLogger->error("Exception processing law {$law['master_id']}: " . $e->getMessage());
        }
        
        // Add delay between laws to avoid overwhelming the server
        if ($index < count($lawsToProcess) - 1) {
            sleep($requestDelay);
        }
    }
    
    $duration = time() - $startTime;
    $watchdogLogger->info("Watchdog completed: {$processed} processed, {$skipped} skipped, {$errors} errors (took {$duration} seconds)");
    $watchdogLogger->info("=== Watchdog finished at " . date('Y-m-d H:i:s') . " ===\n");
    
    echo "Watchdog completed: {$processed} processed, {$skipped} skipped, {$errors} errors\n";
    echo "Duration: {$duration} seconds\n";
    
} catch (\Exception $e) {
    $watchdogLogger->error("Watchdog error: " . $e->getMessage());
    $watchdogLogger->error("Stack trace: " . $e->getTraceAsString());
    error_log("Watchdog error: " . $e->getMessage());
    exit(1);
}

