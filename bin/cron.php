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

$logger->info("Starting cron job");

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
$laws = $scraper->parseListPage($listHtml);

if (empty($laws)) {
    $logger->warning("No laws found on list page");
    echo "WARNING: No laws found on list page\n";
    exit(0);
}

// Process up to 20 newest laws (that aren't already processed)
// The processor will skip laws that haven't changed (based on content hash)
$laws = array_slice($laws, 0, 20);
$logger->info("Found " . count($laws) . " law(s) to process (processing up to 20 newest)");

// Process each law
$processed = 0;
$skipped = 0;
$errors = 0;

foreach ($laws as $law) {
    try {
        if ($processor->processLaw($law)) {
            $processed++;
        } else {
            $skipped++;
        }
    } catch (\Exception $e) {
        $errors++;
        $logger->error("Exception processing law: " . $e->getMessage());
    }
}

$logger->info("Cron job completed: {$processed} processed, {$skipped} skipped, {$errors} errors");
echo "Completed: {$processed} processed, {$skipped} skipped, {$errors} errors\n";

