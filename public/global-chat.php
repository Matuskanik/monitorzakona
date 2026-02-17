<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');

set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Interná chyba: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
    exit;
});
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require_once __DIR__ . '/../vendor/autoload.php';
\App\Maintenance::check();

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

$userId = $auth->getUserId();

$payload = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

$question = trim((string)($payload['question'] ?? $_POST['question'] ?? ''));
$history = $payload['history'] ?? [];
$action = $payload['action'] ?? '';

if ($action === 'test_search' && $auth->isLoggedIn()) {
    $openaiKey = Config::get('OPENAI_API_KEY') ?: getenv('OPENAI_API_KEY');
    if (empty($openaiKey) || $openaiKey === 'your_openai_api_key_here') {
        echo json_encode(['error' => 'OPENAI_API_KEY not configured', 'source' => 'config']);
        exit;
    }
    $body = [
        'model' => 'gpt-4o-search-preview',
        'messages' => [['role' => 'user', 'content' => 'Aké sú výpovedné lehoty na Slovensku? Odpovedz stručne.']],
        'web_search_options' => (object) [],
        'max_tokens' => 300,
    ];
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $openaiKey],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    echo json_encode([
        'http_code' => $code,
        'curl_error' => $err ?: null,
        'response_preview' => $resp ? substr($resp, 0, 500) : null,
        'ok' => ($code === 200 && empty($err)),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'clear') {
    $db->saveGlobalChat($userId, []);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($question === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Chýba otázka.']);
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
    if (!in_array($role, ['user', 'assistant'], true) || !is_string($content)) {
        continue;
    }
    $content = trim($content);
    if ($content === '' || mb_strlen($content, 'UTF-8') > 2000) {
        $content = mb_substr($content, 0, 2000, 'UTF-8');
    }
    $sanitizedHistory[] = ['role' => $role, 'content' => $content];
}
if (count($sanitizedHistory) > 10) {
    $sanitizedHistory = array_slice($sanitizedHistory, -10);
}

$isPaid = $auth->isPaid();

$existingChat = $db->getGlobalChat($userId);
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
            'error' => 'Na ďalšie otázky v globálnom chate aktivujte platenú verziu.',
            'upgrade_redirect' => 'pricing.php',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    $limit = (int) Config::get('CHAT_MESSAGES_PER_LAW_PER_MONTH', '200');
    if ($limit > 0 && $userMessageCount >= $limit) {
        http_response_code(403);
        echo json_encode(['error' => 'Mesačný limit otázok v globálnom chate ste vyčerpali.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Librarian: keywords from question (and last turn of history)
$queryText = $question;
foreach (array_slice($sanitizedHistory, -2) as $item) {
    if (isset($item['content']) && is_string($item['content'])) {
        $queryText .= ' ' . $item['content'];
    }
}
$stopWords = ['a', 'ale', 'ani', 'bez', 'do', 'je', 'jeho', 'jej', 'k', 'kde', 'keď', 'kto', 'ktorá', 'ktoré', 'ktorý', 'ku', 'ma', 'má', 'na', 'nad', 'ne', 'nie', 'no', 'o', 'od', 'po', 'pod', 'pre', 'pri', 'sa', 's', 'so', 'som', 'sú', 'ta', 'tak', 'tá', 'tam', 'te', 'ten', 'tento', 'tie', 'to', 'tu', 'ty', 'u', 'už', 'v', 'vo', 'vy', 'za', 'že', 'z', 'zo'];
$words = preg_split('/\s+/', mb_strtolower($queryText, 'UTF-8'));
$keywords = array_values(array_filter($words, function ($w) use ($stopWords) {
    return mb_strlen($w, 'UTF-8') > 2 && !in_array($w, $stopWords);
}));

// Theme detection: expand keywords and set title-boost terms for known domains (e.g. labour → Zákonník práce)
$laborTriggerWords = [
    'dovolenka', 'dovolenky', 'dovolenku', 'dovolenke', 'zamestnanec', 'zamestnanca', 'zamestnancov',
    'práca', 'pracovný', 'pracovná', 'zákonník', 'nárok', 'mzda', 'mzdy', 'prestávka', 'prestávky',
    'úväzok', 'úväzku', 'plný', 'pracovný', 'pracovnú',
    'výpoveď', 'výpovedné', 'výpovedná', 'lehoty', 'skončenie', 'prepustenie', 'ukončenie',
];
$titleBoostTerms = null;
foreach ($keywords as $kw) {
    foreach ($laborTriggerWords as $trigger) {
        if (mb_strpos($kw, $trigger) !== false || mb_strpos($trigger, $kw) !== false) {
            $titleBoostTerms = ['zákonník práce', 'práce'];
            $keywords = array_unique(array_merge($keywords, ['dovolenka', 'zákonník', 'nárok', 'práce']));
            break 2;
        }
    }
}

$labeledPassages = [];
$maxContextChars = (int) Config::get('GLOBAL_CHAT_CONTEXT_CHARS', '50000');
$totalChars = 0;
$seenChunkIds = [];

// 1) If theme suggests a specific law (e.g. labour), prioritise chunks from matching titles
if ($titleBoostTerms !== null) {
    $titleChunks = $db->getChunksFromLawsWithTitleMatching($titleBoostTerms, null, 80);
    $titleReserveChars = (int) ($maxContextChars * 0.5); // use up to half of context for the main law
    foreach ($titleChunks as $c) {
        $id = $c['law_id'] . '_' . $c['chunk_index'];
        if (isset($seenChunkIds[$id])) {
            continue;
        }
        $len = mb_strlen($c['content'], 'UTF-8');
        if ($totalChars + $len > $titleReserveChars) {
            break;
        }
        $seenChunkIds[$id] = true;
        $labeledPassages[] = [
            'content' => $c['content'],
            'master_id' => $c['master_id'],
            'title' => $c['title'],
            'section_title' => $c['section_title'] ?? null,
        ];
        $totalChars += $len;
    }
}

// 2) Fill with keyword-matched chunks (deduplicated, until context limit)
if (!empty($keywords)) {
    $chunks = $db->getChunksMatchingKeywords($keywords, null, 200);
    foreach ($chunks as $c) {
        $id = ($c['law_id'] ?? '') . '_' . ($c['chunk_index'] ?? '');
        if (isset($seenChunkIds[$id])) {
            continue;
        }
        $len = mb_strlen($c['content'], 'UTF-8');
        if ($totalChars + $len > $maxContextChars) {
            break;
        }
        $seenChunkIds[$id] = true;
        $labeledPassages[] = [
            'content' => $c['content'],
            'master_id' => $c['master_id'],
            'title' => $c['title'],
            'section_title' => $c['section_title'] ?? null,
        ];
        $totalChars += $len;
    }
}

if (empty($labeledPassages)) {
    // No chunks: still call LLM with empty context so it can say "no relevant laws found"
    $labeledPassages[] = [
        'content' => 'V zbierke zákonov neboli nájdené relevantné úryvky pre túto otázku.',
        'master_id' => '-',
        'title' => 'Žiadne úryvky',
        'section_title' => null,
    ];
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
    (int) Config::get('OPENAI_MAX_TOKENS', '4000'),
    $logger
);

$useWebSearch = filter_var(Config::get('GLOBAL_CHAT_WEB_SEARCH', 'true'), FILTER_VALIDATE_BOOLEAN);
$globalChatModel = Config::get('GLOBAL_CHAT_MODEL', 'gpt-4o-search-preview');

try {
    $actualSource = 'fallback';
    if ($useWebSearch) {
        $result = $aiClient->answerFromPassagesWithWebSearch(
            $labeledPassages,
            $question,
            $sanitizedHistory,
            $globalChatModel,
            'medium'
        );
        $answer = $result['answer'];
        $citations = $result['citations'];
        $actualSource = $result['used_web_search'] ? 'web_search' : 'fallback';
    } else {
        $answer = $aiClient->answerFromPassagesWithHistory($labeledPassages, $question, $sanitizedHistory);
        $citations = [];
    }

    $messagesToSave = $messages;
    $messagesToSave[] = ['role' => 'user', 'content' => $question];
    $assistantMsg = ['role' => 'assistant', 'content' => $answer];
    if (!empty($citations)) {
        $assistantMsg['citations'] = $citations;
    }
    $messagesToSave[] = $assistantMsg;
    $db->saveGlobalChat($userId, $messagesToSave);

    $payload = ['answer' => $answer, 'source' => $actualSource];
    if (!empty($citations)) {
        $payload['citations'] = $citations;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
} catch (\Exception $e) {
    $logger->error("Global chat error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Chyba pri spracovaní otázky. Skúste neskôr.']);
}
