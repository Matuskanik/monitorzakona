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
use App\Auth;
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

$db = new Database(Config::get('DB_PATH', 'data/sentinel.db') ?: 'data/sentinel.db');
$auth = new Auth($db);

if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Musíte byť prihlásený.']);
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

$lawId = trim((string)($payload['law_id'] ?? $_POST['law_id'] ?? ''));
$question = trim((string)($payload['question'] ?? ($_POST['question'] ?? '')));
$history = $payload['history'] ?? [];

if ($lawId === '' || $question === '') {
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

// Support both numeric id and master_id (when from JSON fallback, law.php passes master_id as id)
$stmt = $db->getPdo()->prepare("SELECT * FROM laws WHERE id = ? OR master_id = ?");
$stmt->execute([$lawId, $lawId]);
$law = $stmt->fetch();

$fromJson = false;
if (!$law) {
    // Fallback: law from JSON (e.g. on Digital Ocean when DB is empty)
    $jsonPath = __DIR__ . '/data/laws/' . $lawId . '.json';
    if (is_readable($jsonPath)) {
        $json = json_decode(file_get_contents($jsonPath), true);
        if (is_array($json)) {
            $law = [
                'id' => $json['master_id'] ?? $lawId,
                'master_id' => $json['master_id'] ?? $lawId,
                'title' => $json['title'] ?? '',
            ];
            $fromJson = true;
        }
    }
}

if (!$law) {
    http_response_code(404);
    echo json_encode(['error' => 'Zákon nebol nájdený.']);
    exit;
}

$masterId = (string)($law['master_id'] ?? $law['id']);
$userId = $auth->getUserId();
$isPaid = $auth->isPaid();

// Free: 1 question per law total. Paid: cap per law (default 200).
if ($fromJson) {
    $existingChat = $db->getChatByMasterId($userId, $masterId);
} else {
    $internalLawId = (int) $law['id'];
    $existingChat = $db->getUserChat($userId, $internalLawId);
}
$messages = $existingChat['messages'] ?? [];
$userMessageCount = 0;
foreach ($messages as $m) {
    if (isset($m['role']) && $m['role'] === 'user') {
        $userMessageCount++;
    }
}

if (!$isPaid) {
    if ($userMessageCount >= 1) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Na ďalšie otázky k tomuto zákonu aktivujte platenú verziu.',
            'upgrade_redirect' => 'pricing.php',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    $limit = (int) Config::get('CHAT_MESSAGES_PER_LAW_PER_MONTH', '200');
    if ($limit > 0 && $userMessageCount >= $limit) {
        http_response_code(403);
        echo json_encode(['error' => 'Mesačný limit konverzácie pre tento zákon ste vyčerpali. Skúste znova neskôr.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$storagePath = Config::get('STORAGE_PATH', 'storage');
if (!str_starts_with($storagePath, '/')) {
    $storagePath = dirname(__DIR__) . '/' . $storagePath;
}

$combinedPath = $storagePath . '/' . $masterId . '/combined.txt';
$txtInPublic = __DIR__ . '/data/laws/' . $masterId . '.txt';

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
    // Persist chat so free 1-question limit is enforced server-side
    $messagesToSave = $messages;
    $messagesToSave[] = ['role' => 'user', 'content' => $question];
    $messagesToSave[] = ['role' => 'assistant', 'content' => $answer];
    if ($fromJson) {
        $db->saveChatByMasterId($userId, $masterId, $messagesToSave);
    } else {
        $db->saveUserChat($userId, (int) $law['id'], $messagesToSave);
    }

    echo json_encode([
        'answer' => $answer
    ], JSON_UNESCAPED_UNICODE);
} catch (\Exception $e) {
    $logger->error("Law chat error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Chyba pri spracovaní otázky. Skúste neskôr.']);
}
