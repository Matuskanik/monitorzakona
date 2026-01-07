#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\Logger;
use App\Scraper;

// Try to load config, but fallback to defaults if .env is not accessible
$dbPath = null;
$logPath = null;
$requestDelay = 3;
$maxRetries = 3;
$retryDelay = 5;

try {
    Config::load();
    $dbPath = Config::get('DB_PATH');
    $logPath = Config::get('LOG_PATH', __DIR__ . '/../storage/logs');
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
}

$logger = new Logger($logPath . '/app.log');
$db = new Database($dbPath);

$scraper = new Scraper(
    $logger,
    $requestDelay,
    $maxRetries,
    $retryDelay
);

echo "Updating delivery dates for laws without dates...\n\n";

// Get all laws without approval_date
$stmt = $db->getPdo()->prepare("SELECT id, master_id, title, source_url, approval_date FROM laws WHERE approval_date IS NULL OR approval_date = '' ORDER BY created_at DESC");
$stmt->execute();
$laws = $stmt->fetchAll();

if (empty($laws)) {
    echo "No laws found without dates.\n";
    exit(0);
}

echo "Found " . count($laws) . " laws without dates.\n\n";

$updated = 0;
$errors = 0;

foreach ($laws as $index => $law) {
    $lawNum = $index + 1;
    $total = count($laws);
    echo "[{$lawNum}/{$total}] Processing: {$law['title']}\n";
    echo "  MasterID: {$law['master_id']}\n";
    
    try {
        // Fetch detail page
        $detailHtml = $scraper->fetchDetailPage($law['source_url']);
        
        // Extract delivery date
        $deliveryDate = $scraper->extractDeliveryDate($detailHtml);
        
        if ($deliveryDate) {
            // Update database
            $updateStmt = $db->getPdo()->prepare("UPDATE laws SET approval_date = ? WHERE id = ?");
            $updateStmt->execute([$deliveryDate, $law['id']]);
            echo "  ✓ Updated date: {$deliveryDate}\n";
            $updated++;
        } else {
            echo "  ⊘ Date not found on page\n";
        }
        
        // Add delay between requests
        if ($index < count($laws) - 1) {
            sleep($requestDelay);
        }
        
    } catch (\Exception $e) {
        $errors++;
        $logger->error("Error updating date for law {$law['master_id']}: " . $e->getMessage());
        echo "  ✗ Error: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

echo str_repeat("=", 60) . "\n";
echo "Completed: {$updated} updated, {$errors} errors\n";
echo str_repeat("=", 60) . "\n";

