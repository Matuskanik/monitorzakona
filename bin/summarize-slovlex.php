#!/usr/bin/env php
<?php

/**
 * Generuje AI zhrnutie pre zákony zo Slov-Lexu (origin=slovlex_zz),
 * ktoré majú text, ale nemajú plné ai_summary (summary_paragraph, affected_groups, positives, negatives, how_to_react).
 *
 * Použitie: php bin/summarize-slovlex.php [limit] [rok]
 *   limit = koľko zákonov spracovať (default 10). Pre všetky zadaj 0 alebo veľké číslo.
 *   rok   = voliteľne iba zákony z daného roka (napr. 2026). Ak nezadané, spracuje všetky.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\Logger;
use App\OpenAIClient;

ob_implicit_flush(1);
if (ob_get_level()) {
    ob_end_flush();
}

try {
    Config::load();
} catch (\Exception $e) {
    die("Configuration error: " . $e->getMessage());
}

$limit = isset($argv[1]) && is_numeric($argv[1]) ? (int) $argv[1] : 10;
if ($limit <= 0) {
    $limit = 999999;
}
$year = isset($argv[2]) && is_numeric($argv[2]) ? (int) $argv[2] : null;

$dbPath = Config::get('DB_PATH', 'data/sentinel.db');
$storagePath = Config::get('STORAGE_PATH', 'storage');
$logPath = Config::get('LOG_PATH', __DIR__ . '/../storage/logs');
if (!str_starts_with($storagePath, '/')) {
    $storagePath = dirname(__DIR__) . '/' . $storagePath;
}
if (!str_starts_with($logPath, '/')) {
    $logPath = dirname(__DIR__) . '/' . $logPath;
}

$db = new Database($dbPath ?: 'data/sentinel.db');
$logger = new Logger($logPath . '/app.log');

$openaiKey = Config::get('OPENAI_API_KEY');
if (empty($openaiKey) || $openaiKey === 'your_openai_api_key_here') {
    die("OPENAI_API_KEY nie je nakonfigurovaný v .env\n");
}

$aiClient = new OpenAIClient(
    $openaiKey,
    Config::get('OPENAI_MODEL', 'gpt-4o'),
    (int) Config::get('OPENAI_MAX_TOKENS', '4000'),
    $logger
);

// Zákony, ktoré majú text, ale nemajú plné ai_summary (summary_paragraph)
$sql = "
    SELECT id, master_id, title, external_id, ai_summary
    FROM laws
    WHERE origin = 'slovlex_zz'
    AND (ai_summary IS NULL OR ai_summary = '' OR ai_summary NOT LIKE '%summary_paragraph%')
";
$params = [];
if ($year !== null) {
    $sql .= " AND master_id LIKE ?";
    $params[] = 'slovlex-ZZ-' . $year . '-%';
}
$sql .= " ORDER BY id DESC LIMIT " . (int) $limit;

$stmt = $db->getPdo()->prepare($sql);
$params ? $stmt->execute($params) : $stmt->execute();
$laws = $stmt->fetchAll(\PDO::FETCH_ASSOC);

$yearInfo = $year ? " (rok {$year})" : "";
echo "Na spracovanie: " . count($laws) . " zákonov{$yearInfo}\n";
@flush();

if (empty($laws)) {
    echo $year ? "Všetky Slov-Lex zákony z roka {$year} už majú zhrnutie.\n" : "Všetky Slov-Lex zákony už majú zhrnutie.\n";
    exit(0);
}

$processed = 0;
$errors = 0;

foreach ($laws as $law) {
    $masterId = $law['master_id'];
    $extId = $law['external_id'] ?? '';
    if ($extId === '' && preg_match('/^slovlex-ZZ-(\d+)-(\d+)$/', $masterId, $m)) {
        $extId = $m[1] . '/' . $m[2];
    }
    $combinedPath = $storagePath . '/slovlex_zz/' . str_replace('\\', '/', $extId) . '/combined.txt';

    if (!file_exists($combinedPath) || !is_readable($combinedPath)) {
        echo "s"; // skip - no text
        @flush();
        continue;
    }

    $text = file_get_contents($combinedPath);
    if (strlen(trim($text)) < 100) {
        echo "s";
        @flush();
        continue;
    }

    try {
        $aiSummary = $aiClient->generateSummary($text);
        $aiSummaryJson = json_encode($aiSummary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $db->getPdo()->prepare("
            UPDATE laws SET ai_summary = ?, processing_status = 'completed', updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ")->execute([$aiSummaryJson, $law['id']]);

        $processed++;
        echo ".";
    } catch (\Throwable $e) {
        $logger->error("Summarize Slov-Lex {$masterId}: " . $e->getMessage());
        $errors++;
        echo "E";
    }
    @flush();
}

echo "\nHotovo. Spracované: {$processed}, chyby: {$errors}\n";
@flush();
