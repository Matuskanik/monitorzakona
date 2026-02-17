#!/usr/bin/env php
<?php
/**
 * Test if gpt-4o-search-preview / gpt-4o-mini-search-preview API works.
 * Usage: php bin/test-search-api.php
 */
require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;

Config::load();
$key = Config::get('OPENAI_API_KEY') ?: getenv('OPENAI_API_KEY');
if (empty($key) || $key === 'your_openai_api_key_here') {
    die("OPENAI_API_KEY nie je nastavený v .env\n");
}

$body = [
    'model' => 'gpt-4o-search-preview',
    'messages' => [
        ['role' => 'user', 'content' => 'Aké sú výpovedné lehoty podľa Zákonníka práce na Slovensku? Odpovedz stručne.'],
    ],
    'web_search_options' => (object) [],
    'max_tokens' => 500,
];

echo "Testujem gpt-4o-search-preview...\n";

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
    ],
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 60,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($response === false || !empty($error)) {
    die("CHYBA: {$error}\n");
}

echo "HTTP {$httpCode}\n";

if ($httpCode !== 200) {
    echo "Odpoveď: " . substr($response, 0, 500) . "\n";
    echo "\nAk je 404/403: model môže byť nedostupný (napr. Free tier). Skúste gpt-4o-mini-search-preview.\n";
    exit(1);
}

$data = json_decode($response, true);
$content = $data['choices'][0]['message']['content'] ?? '';
$annotations = $data['choices'][0]['message']['annotations'] ?? [];

echo "\n--- Odpoveď ---\n" . substr($content, 0, 800) . "\n";
echo "\n--- Citácie: " . count($annotations) . " ---\n";
foreach (array_slice($annotations, 0, 3) as $a) {
    $uc = $a['url_citation'] ?? $a;
    echo "- " . ($uc['url'] ?? '?') . "\n";
}
echo "\nOK: Search API funguje.\n";
