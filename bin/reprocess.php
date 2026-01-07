#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\Scraper;
use App\DocumentExtractor;
use App\OpenAIClient;
use App\LawProcessor;
use App\Logger;

try {
    Config::load();
} catch (\Exception $e) {
    die("Configuration error: " . $e->getMessage() . "\n");
}

$db = new Database(Config::get('DB_PATH'));
$logger = new Logger(Config::get('LOG_PATH', __DIR__ . '/../storage/logs/app.log'));

// Get law ID from command line or use latest
$lawId = $argv[1] ?? null;

if ($lawId) {
    // Reprocess specific law
    $law = $db->getPdo()->prepare("SELECT * FROM laws WHERE id = ?");
    $law->execute([$lawId]);
    $law = $law->fetch();
    
    if (!$law) {
        die("Law with ID {$lawId} not found.\n");
    }
    
    $masterId = $law['master_id'];
} else {
    // Get latest law
    $laws = $db->getLatestLaws(1);
    if (empty($laws)) {
        die("No laws found in database.\n");
    }
    $law = $laws[0];
    $masterId = $law['master_id'];
    $lawId = $law['id'];
}

echo "Reprocessing law ID: {$lawId}, MasterID: {$masterId}\n";
echo "Title: {$law['title']}\n\n";

// Clear AI summary to force reprocessing
$stmt = $db->getPdo()->prepare("UPDATE laws SET ai_summary = NULL, processing_status = 'pending' WHERE id = ?");
$stmt->execute([$lawId]);

echo "AI summary cleared. Now reprocessing...\n\n";

// Initialize components
$scraper = new Scraper($logger);
$extractor = new DocumentExtractor($logger);
$aiClient = new OpenAIClient(
    Config::get('OPENAI_API_KEY'),
    Config::get('OPENAI_MODEL', 'gpt-4o-mini'),
    Config::get('OPENAI_MAX_TOKENS', 2000),
    $logger
);
$processor = new LawProcessor(
    $scraper,
    $extractor,
    $aiClient,
    $db,
    $logger,
    Config::get('STORAGE_PATH', __DIR__ . '/../storage')
);

// Get law data
$lawData = [
    'master_id' => $law['master_id'],
    'title' => $law['title'],
    'approval_date' => $law['approval_date'],
    'url' => $law['source_url']
];

// Force reprocessing by modifying content hash slightly
// This will make the system think content changed
$existing = $db->findLawByMasterId($masterId);
if ($existing) {
    // Temporarily change content_hash to force reprocessing
    $stmt = $db->getPdo()->prepare("UPDATE laws SET content_hash = ? WHERE id = ?");
    $stmt->execute([$existing['content_hash'] . '_force_reprocess', $lawId]);
}

// Process the law
$success = $processor->processLaw($lawData);

if ($success) {
    echo "\n✓ Law reprocessed successfully!\n";
    echo "View it at: http://localhost:8000/law.php?id={$lawId}\n";
} else {
    echo "\n✗ Failed to reprocess law.\n";
    exit(1);
}

