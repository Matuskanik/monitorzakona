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

echo "Reprocessing all laws to add tags...\n\n";

// Get all laws
$stmt = $db->getPdo()->query("SELECT id, master_id, title, approval_date, source_url FROM laws ORDER BY id");
$laws = $stmt->fetchAll();

if (empty($laws)) {
    echo "No laws found in database.\n";
    exit(0);
}

echo "Found " . count($laws) . " laws to reprocess.\n\n";

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
        // Clear AI summary to force reprocessing
        $updateStmt = $db->getPdo()->prepare("UPDATE laws SET ai_summary = NULL, processing_status = 'pending' WHERE id = ?");
        $updateStmt->execute([$law['id']]);
        
        // Get law data
        $lawData = [
            'master_id' => $law['master_id'],
            'title' => $law['title'],
            'approval_date' => $law['approval_date'],
            'url' => $law['source_url']
        ];
        
        // Process the law
        if ($processor->processLaw($lawData)) {
            $processed++;
            echo "  ✓ Success\n";
        } else {
            $skipped++;
            echo "  ⊘ Skipped\n";
        }
        
        // Add delay between laws to avoid overwhelming the server
        if ($index < count($laws) - 1) {
            sleep($requestDelay);
        }
        
    } catch (\Exception $e) {
        $errors++;
        $logger->error("Exception processing law {$law['master_id']}: " . $e->getMessage());
        echo "  ✗ Error: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

$duration = time() - $startTime;
echo str_repeat("=", 60) . "\n";
echo "Completed: {$processed} processed, {$skipped} skipped, {$errors} errors\n";
echo "Duration: {$duration} seconds (" . round($duration / 60, 1) . " minutes)\n";
echo str_repeat("=", 60) . "\n";

