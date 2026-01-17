#!/usr/bin/env php
<?php

/**
 * One-time script to process initial 20 laws from NR SR website
 * This ensures we have 20 laws in the database for the website
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\Logger;
use App\Scraper;
use App\DocumentExtractor;
use App\OpenAIClient;
use App\LawProcessor;

try {
    Config::load();
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

$logger = new Logger(Config::get('LOG_PATH') . '/app.log');
$db = new Database(Config::get('DB_PATH'));

$scraper = new Scraper(
    $logger,
    (int)Config::get('REQUEST_DELAY_SECONDS', '3'),
    (int)Config::get('MAX_RETRIES', '3'),
    (int)Config::get('RETRY_DELAY_SECONDS', '5')
);

$extractor = new DocumentExtractor($logger);

$openaiKey = Config::get('OPENAI_API_KEY');
if (empty($openaiKey) || $openaiKey === 'your_openai_api_key_here') {
    $logger->error("OPENAI_API_KEY not configured");
    echo "ERROR: OPENAI_API_KEY not configured in .env\n";
    exit(1);
}

$aiClient = new OpenAIClient(
    $openaiKey,
    Config::get('OPENAI_MODEL', 'gpt-4o-mini'),
    (int)Config::get('OPENAI_MAX_TOKENS', '2000'),
    $logger
);

$processor = new LawProcessor(
    $scraper,
    $extractor,
    $aiClient,
    $db,
    $logger,
    Config::get('STORAGE_PATH', 'storage')
);

$logger->info("Starting initial 20 laws processing");

// Fetch list page
$listUrl = Config::get('NR_SR_LIST_URL', 'https://www.nrsr.sk/web/default.aspx?SectionId=184');
$listHtml = $scraper->fetchListPage($listUrl);

// Save snapshot
$snapshotDir = Config::get('STORAGE_PATH', 'storage') . '/snapshots';
if (!is_dir($snapshotDir)) {
    mkdir($snapshotDir, 0755, true);
}
file_put_contents($snapshotDir . '/source_list_' . date('Y-m-d_H-i-s') . '.html', $listHtml);

// Parse laws
$allLaws = $scraper->parseListPage($listHtml);

if (empty($allLaws)) {
    $logger->warning("No laws found on list page");
    echo "WARNING: No laws found on list page\n";
    exit(0);
}

// Get 20 newest laws
$laws = array_slice($allLaws, 0, 20);
$logger->info("Found " . count($allLaws) . " total laws, processing 20 newest");

echo "Processing 20 newest laws from NR SR website...\n";
echo "This may take a while (each law needs to be downloaded and analyzed)...\n\n";

// Process each law (even if it already exists - processor will handle duplicates)
$processed = 0;
$skipped = 0;
$errors = 0;

foreach ($laws as $index => $law) {
    $lawNum = $index + 1;
    echo "[{$lawNum}/20] Processing: {$law['title']}\n";
    
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
        $logger->error("Exception processing law: " . $e->getMessage());
        echo "  ✗ Error: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

$logger->info("Initial processing completed: {$processed} processed, {$skipped} skipped, {$errors} errors");
echo "\nCompleted: {$processed} processed, {$skipped} skipped, {$errors} errors\n";
echo "Now run: php bin/generate-json-data.php\n";
