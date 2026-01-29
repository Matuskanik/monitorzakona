<?php

// Suppress HTML error output - return JSON errors instead
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');

// Global exception handler
set_exception_handler(function($e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Interná chyba: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

// Custom error handler to return JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\OpenAIClient;
use App\Logger;

try {
    Config::load();
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration error.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$payload = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

$lawId = $payload['law_id'] ?? ($_POST['law_id'] ?? null);
$question = trim((string)($payload['question'] ?? ($_POST['question'] ?? '')));
$history = $payload['history'] ?? [];

if (empty($lawId) || $question === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Chýba ID zákona alebo otázka.']);
    exit;
}

if (mb_strlen($question, 'UTF-8') > 1000) {
    http_response_code(400);
    echo json_encode(['error' => 'Otázka je príliš dlhá.']);
    exit;
}

if (!is_array($history)) {
    $history = [];
}

$sanitizedHistory = [];
foreach ($history as $item) {
    if (!is_array($item)) {
        continue;
    }
    $role = $item['role'] ?? '';
    $content = $item['content'] ?? '';
    if (!in_array($role, ['user', 'assistant'], true)) {
        continue;
    }
    if (!is_string($content)) {
        continue;
    }
    $content = trim($content);
    if ($content === '') {
        continue;
    }
    if (mb_strlen($content, 'UTF-8') > 2000) {
        $content = mb_substr($content, 0, 2000, 'UTF-8');
    }
    $sanitizedHistory[] = [
        'role' => $role,
        'content' => $content
    ];
}

if (count($sanitizedHistory) > 10) {
    $sanitizedHistory = array_slice($sanitizedHistory, -10);
}

$db = new Database(Config::get('DB_PATH', 'data/sentinel.db') ?: 'data/sentinel.db');
// Support both numeric id and master_id (when from JSON fallback, law.php passes master_id as id)
$stmt = $db->getPdo()->prepare("SELECT * FROM laws WHERE id = ? OR master_id = ?");
$stmt->execute([$lawId, $lawId]);
$law = $stmt->fetch();

if (!$law) {
    http_response_code(404);
    echo json_encode(['error' => 'Zákon nebol nájdený.']);
    exit;
}

$storagePath = Config::get('STORAGE_PATH', 'storage');
if (!str_starts_with($storagePath, '/')) {
    $storagePath = dirname(__DIR__) . '/' . $storagePath;
}

$combinedPath = $storagePath . '/' . $law['master_id'] . '/combined.txt';
$txtInPublic = __DIR__ . '/data/laws/' . $law['master_id'] . '.txt';

if (file_exists($combinedPath) && is_readable($combinedPath)) {
    $lawText = file_get_contents($combinedPath);
} elseif (is_readable($txtInPublic)) {
    $lawText = file_get_contents($txtInPublic);
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Text zákona nie je dostupný.']);
    exit;
}
if ($lawText === false || trim($lawText) === '') {
    http_response_code(404);
    echo json_encode(['error' => 'Text zákona je prázdny.']);
    exit;
}

$openaiKey = Config::get('OPENAI_API_KEY');
if (empty($openaiKey) || $openaiKey === 'your_openai_api_key_here') {
    http_response_code(500);
    echo json_encode(['error' => 'OpenAI API nie je nakonfigurované.']);
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
    (int)Config::get('OPENAI_MAX_TOKENS', '4000'),
    $logger
);

try {
    $answer = $aiClient->answerQuestionWithHistory($lawText, $question, $sanitizedHistory);
    echo json_encode([
        'answer' => $answer
    ], JSON_UNESCAPED_UNICODE);
} catch (\Exception $e) {
    $logger->error("Law chat error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Chyba pri spracovaní otázky. Skúste neskôr.']);
}
