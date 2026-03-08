<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode(['error' => 'Interná chyba servera.'], JSON_UNESCAPED_UNICODE);
    exit;
});

require_once __DIR__ . '/../vendor/autoload.php';
\App\Maintenance::check();

use App\Auth;
use App\Config;
use App\Database;
use App\Logger;
use App\OpenAIClient;
use App\Security;

try {
    Config::load();
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration error.'], JSON_UNESCAPED_UNICODE);
    exit;
}

Security::setSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!Security::checkRateLimit('analyze_' . $ip, 6, 3600)) {
    http_response_code(429);
    echo json_encode(['error' => 'Limit požiadaviek na analýzu bol prekročený. Skúste znova neskôr.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$db = new Database(Config::get('DB_PATH', 'data/sentinel.db') ?: 'data/sentinel.db');
$auth = new Auth($db);
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Pre analýzu sa musíte prihlásiť.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    $payload = [];
}

$lawId = Security::validateIntegerId((string) ($payload['law_id'] ?? ''));
if ($lawId === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Neplatné ID zákona.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = $db->getPdo()->prepare("SELECT * FROM laws WHERE id = ?");
$stmt->execute([$lawId]);
$law = $stmt->fetch();
if (!$law) {
    http_response_code(404);
    echo json_encode(['error' => 'Dokument nebol nájdený.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$existingSummary = [];
if (!empty($law['ai_summary'])) {
    $decoded = json_decode((string) $law['ai_summary'], true);
    if (is_array($decoded)) {
        $existingSummary = $decoded;
    }
}
if (trim((string) ($existingSummary['summary_paragraph'] ?? '')) !== '') {
    echo json_encode(['success' => true, 'already_processed' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

$storagePath = Config::get('STORAGE_PATH', 'storage');
if (!str_starts_with($storagePath, '/')) {
    $storagePath = dirname(__DIR__) . '/' . $storagePath;
}

$masterId = (string) ($law['master_id'] ?? '');
$origin = (string) ($law['origin'] ?? 'nrsr');

if ($origin === 'slovlex_zz') {
    $extId = (string) ($law['external_id'] ?? '');
    if ($extId === '' && preg_match('/^slovlex-ZZ-(\d+)-(\d+)$/', $masterId, $m)) {
        $extId = $m[1] . '/' . $m[2];
    }
    $combinedPath = $storagePath . '/slovlex_zz/' . str_replace('\\', '/', $extId) . '/combined.txt';
} else {
    $combinedPath = $storagePath . '/' . $masterId . '/combined.txt';
}

if (!is_readable($combinedPath)) {
    http_response_code(404);
    echo json_encode(['error' => 'Text dokumentu nie je dostupný.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$text = file_get_contents($combinedPath);
if (!is_string($text) || mb_strlen(trim($text), 'UTF-8') < 100) {
    http_response_code(422);
    echo json_encode(['error' => 'Text dokumentu je príliš krátky na analýzu.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$openaiKey = Config::get('OPENAI_API_KEY');
if (empty($openaiKey) || $openaiKey === 'your_openai_api_key_here') {
    http_response_code(500);
    echo json_encode(['error' => 'OpenAI API nie je nakonfigurované.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$logPath = Config::get('LOG_PATH', 'storage/logs');
if (!str_starts_with($logPath, '/')) {
    $logPath = dirname(__DIR__) . '/' . $logPath;
}
$logger = new Logger($logPath . '/app.log');

$aiClient = new OpenAIClient(
    $openaiKey,
    Config::get('OPENAI_MODEL', 'gpt-4o'),
    (int) Config::get('OPENAI_MAX_TOKENS', '2000'),
    $logger
);

try {
    $summary = $aiClient->generateSummary($text);
    $summaryJson = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $humanTitle = !empty($summary['human_title']) ? trim($summary['human_title']) : null;

    $db->getPdo()->prepare("
        UPDATE laws
        SET ai_summary = ?, human_title = ?, processing_status = 'completed', updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ")->execute([$summaryJson, $humanTitle, $lawId]);

    $db->logProcessing((string) ($law['master_id'] ?? $lawId), 'success', 'On-demand law analysis');
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    $logger->error("On-demand analysis failed for law {$lawId}: " . $e->getMessage());
    $db->logProcessing((string) ($law['master_id'] ?? $lawId), 'error', 'On-demand analysis failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Analýzu sa nepodarilo dokončiť. Skúste to znova neskôr.'], JSON_UNESCAPED_UNICODE);
}
