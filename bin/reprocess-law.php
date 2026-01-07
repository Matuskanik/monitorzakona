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

// Try to load config, but fallback to defaults if .env is not accessible
$dbPath = null;
$logPath = null;
$openaiKey = null;
$openaiModel = 'gpt-4o-mini';
$openaiMaxTokens = 2000;
$storagePath = __DIR__ . '/../storage';

try {
    Config::load();
    $dbPath = Config::get('DB_PATH');
    $logPath = Config::get('LOG_PATH', __DIR__ . '/../storage/logs');
    $openaiKey = Config::get('OPENAI_API_KEY');
    $openaiModel = Config::get('OPENAI_MODEL', 'gpt-4o-mini');
    $openaiMaxTokens = (int)Config::get('OPENAI_MAX_TOKENS', '2000');
    $storagePath = Config::get('STORAGE_PATH', __DIR__ . '/../storage');
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
    
    echo "Warning: Using fallback configuration (could not load .env)\n";
    echo "DB Path: {$dbPath}\n";
    echo "Log Path: {$logPath}\n\n";
}

$db = new Database($dbPath);
$logger = new Logger($logPath . '/app.log');

// Get law ID from command line or use latest
$lawId = $argv[1] ?? null;

if ($lawId) {
    $law = $db->getPdo()->prepare("SELECT * FROM laws WHERE id = ?");
    $law->execute([$lawId]);
    $law = $law->fetch();
    
    if (!$law) {
        die("Law with ID {$lawId} not found.\n");
    }
} else {
    $laws = $db->getLatestLaws(1);
    if (empty($laws)) {
        die("No laws found in database.\n");
    }
    $law = $laws[0];
    $lawId = $law['id'];
}

echo "Reprocessing law ID: {$lawId}, MasterID: {$law['master_id']}\n";
echo "Title: {$law['title']}\n\n";

// Initialize components
$scraper = new Scraper($logger);
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

// Get law data
$lawData = [
    'master_id' => $law['master_id'],
    'title' => $law['title'],
    'approval_date' => $law['approval_date'],
    'url' => $law['source_url']
];

// Process the law
echo "Processing law...\n";
$success = $processor->processLaw($lawData);

if ($success) {
    echo "\n✓ Law reprocessed successfully!\n";
    echo "View it at: http://localhost:8000/law.php?id={$lawId}\n";
} else {
    echo "\n✗ Failed to reprocess law.\n";
    exit(1);
}

