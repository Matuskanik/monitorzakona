#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\Logger;
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
$limit = 20; // Number of laws to process

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
    
    // Allow override from command line
    if (isset($argv[1]) && is_numeric($argv[1])) {
        $limit = (int)$argv[1];
    }
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
        die("ERROR: OPENAI_API_KEY not found. Please set it in .env file or environment variable.\n");
    }
    
    // Allow override from command line
    if (isset($argv[1]) && is_numeric($argv[1])) {
        $limit = (int)$argv[1];
    }
    
    echo "Warning: Using fallback configuration (could not load .env)\n";
    echo "DB Path: {$dbPath}\n";
    echo "Log Path: {$logPath}\n";
    echo "Processing limit: {$limit} laws\n\n";
}

$logger = new Logger($logPath . '/app.log');
$db = new Database($dbPath);

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

$logger->info("Starting processing of recent {$limit} laws");

// Fetch list page
echo "Fetching laws list from NR SR...\n";
$listHtml = $scraper->fetchListPage($nrSrListUrl);

// Save snapshot
$snapshotDir = $storagePath . '/snapshots';
if (!is_dir($snapshotDir)) {
    mkdir($snapshotDir, 0755, true);
}
file_put_contents($snapshotDir . '/source_list_' . date('Y-m-d_H-i-s') . '.html', $listHtml);

// Parse laws
echo "Parsing laws list...\n";
$laws = $scraper->parseListPage($listHtml);

if (empty($laws)) {
    $logger->warning("No laws found on list page");
    echo "WARNING: No laws found on list page\n";
    exit(0);
}

// Limit to specified number
$laws = array_slice($laws, 0, $limit);
$logger->info("Found " . count($laws) . " law(s) to process (limited to {$limit})");
echo "Processing " . count($laws) . " laws...\n\n";

// Process each law
$processed = 0;
$skipped = 0;
$errors = 0;
$startTime = time();

foreach ($laws as $index => $law) {
    $lawNum = $index + 1;
    $total = count($laws);
    echo "[{$lawNum}/{$total}] Processing: {$law['title']}\n";
    echo "  MasterID: {$law['master_id']}\n";
    
    try {
        if ($processor->processLaw($law)) {
            $processed++;
            echo "  ✓ Success\n";
        } else {
            $skipped++;
            echo "  ⊘ Skipped (already processed or no attachments)\n";
        }
    } catch (\Exception $e) {
        $errors++;
        $logger->error("Exception processing law {$law['master_id']}: " . $e->getMessage());
        echo "  ✗ Error: " . $e->getMessage() . "\n";
    }
    
    // Add delay between laws to avoid overwhelming the server
    if ($index < count($laws) - 1) {
        sleep($requestDelay);
    }
    
    echo "\n";
}

$duration = time() - $startTime;
$logger->info("Processing completed: {$processed} processed, {$skipped} skipped, {$errors} errors (took {$duration} seconds)");
echo "\n" . str_repeat("=", 60) . "\n";
echo "Completed: {$processed} processed, {$skipped} skipped, {$errors} errors\n";
echo "Duration: {$duration} seconds\n";
echo str_repeat("=", 60) . "\n";

