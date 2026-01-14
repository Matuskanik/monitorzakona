#!/usr/bin/env php
<?php

/**
 * Script to process all laws from year 2025
 * Fetches all laws from NR SR and processes those from 2025
 */

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

echo "Processing all laws from year 2025...\n\n";

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
$allLaws = $scraper->parseListPage($listHtml);

if (empty($allLaws)) {
    $logger->warning("No laws found on list page");
    echo "WARNING: No laws found on list page\n";
    exit(0);
}

echo "Found " . count($allLaws) . " total laws on list page.\n\n";

// Filter laws from 2025
// We'll need to fetch detail pages to get delivery dates for filtering
$laws2025 = [];
$processedCount = 0;

foreach ($allLaws as $index => $law) {
    $total = count($allLaws);
    echo "[Processing " . ($index + 1) . "/{$total}] Checking: {$law['title']}\n";
    
    // If we already have approval_date, check it
    if (!empty($law['approval_date'])) {
        // Check if date contains 2025
        if (strpos($law['approval_date'], '2025') !== false) {
            $laws2025[] = $law;
            echo "  ✓ From 2025 (date: {$law['approval_date']})\n";
            continue;
        }
    }
    
    // If no date or date doesn't match, fetch detail page to get delivery date
    try {
        $detailHtml = $scraper->fetchDetailPage($law['url']);
        $deliveryDate = $scraper->extractDeliveryDate($detailHtml);
        
        if ($deliveryDate && strpos($deliveryDate, '2025') !== false) {
            $law['approval_date'] = $deliveryDate;
            $laws2025[] = $law;
            echo "  ✓ From 2025 (date: {$deliveryDate})\n";
        } else {
            echo "  ⊘ Not from 2025 (date: " . ($deliveryDate ?: 'not found') . ")\n";
        }
        
        sleep($requestDelay); // Delay between requests
        
    } catch (\Exception $e) {
        $logger->error("Error checking law {$law['master_id']}: " . $e->getMessage());
        echo "  ✗ Error: " . $e->getMessage() . "\n";
    }
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "Found " . count($laws2025) . " laws from year 2025\n";
echo str_repeat("=", 60) . "\n\n";

if (empty($laws2025)) {
    echo "No laws from 2025 found.\n";
    exit(0);
}

// Filter out already processed laws
$newLaws = [];
foreach ($laws2025 as $law) {
    $existing = $db->findLawByMasterId($law['master_id']);
    if (!$existing) {
        $newLaws[] = $law;
    } else {
        echo "Skipping already processed: {$law['master_id']}\n";
    }
}

echo "Processing " . count($newLaws) . " new laws from 2025...\n\n";

// Process each law
$processed = 0;
$skipped = 0;
$errors = 0;
$startTime = time();

foreach ($newLaws as $index => $law) {
    $lawNum = $index + 1;
    $total = count($newLaws);
    echo "[{$lawNum}/{$total}] Processing: {$law['title']}\n";
    echo "  MasterID: {$law['master_id']}\n";
    
    try {
        if ($processor->processLaw($law)) {
            $processed++;
            echo "  ✓ Success\n";
        } else {
            $skipped++;
            echo "  ⊘ Skipped\n";
        }
    } catch (\Exception $e) {
        $errors++;
        $logger->error("Exception processing law {$law['master_id']}: " . $e->getMessage());
        echo "  ✗ Error: " . $e->getMessage() . "\n";
    }
    
    // Add delay between laws to avoid overwhelming the server
    if ($index < count($newLaws) - 1) {
        sleep($requestDelay);
    }
    
    echo "\n";
}

$duration = time() - $startTime;
$logger->info("Year 2025 processing completed: {$processed} processed, {$skipped} skipped, {$errors} errors (took {$duration} seconds)");
echo "\n" . str_repeat("=", 60) . "\n";
echo "Completed: {$processed} processed, {$skipped} skipped, {$errors} errors\n";
echo "Duration: {$duration} seconds (" . round($duration / 60, 1) . " minutes)\n";
echo str_repeat("=", 60) . "\n";

