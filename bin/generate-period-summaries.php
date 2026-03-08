#!/usr/bin/env php
<?php

/**
 * Generuje AI zhrnutia za obdobie (mesiac, štvrťrok, rok) na základe spracovaných zákonov
 * zo Slov-Lexu a NR SR. Výsledky sa ukladajú do period_summaries a zobrazujú na úvodnej stránke.
 *
 * Použitie: php bin/generate-period-summaries.php [rok] [--month=N] [--quarter=N] [--year-only]
 *   rok = rok na spracovanie (default: aktuálny)
 *   --month=N = spracuj iba mesiac N (1-12)
 *   --quarter=N = spracuj iba štvrťrok N (1-4)
 *   --year-only = spracuj iba ročné zhrnutie
 *
 * Bez parametrov: spracuje aktuálny mesiac, štvrťrok a rok.
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

$year = isset($argv[1]) && is_numeric($argv[1]) ? (int) $argv[1] : (int) date('Y');
$monthOnly = null;
$quarterOnly = null;
$yearOnly = false;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--month=')) {
        $monthOnly = (int) substr($arg, 8);
    } elseif (str_starts_with($arg, '--quarter=')) {
        $quarterOnly = (int) substr($arg, 10);
    } elseif ($arg === '--year-only') {
        $yearOnly = true;
    }
}

$dbPath = Config::get('DB_PATH', 'data/sentinel.db');
$logPath = Config::get('LOG_PATH', __DIR__ . '/../storage/logs');
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

$monthNames = [
    1 => 'január', 2 => 'február', 3 => 'marec', 4 => 'apríl', 5 => 'máj', 6 => 'jún',
    7 => 'júl', 8 => 'august', 9 => 'september', 10 => 'október', 11 => 'november', 12 => 'december'
];

$tasks = [];

if ($yearOnly) {
    $tasks[] = ['type' => 'year', 'year' => $year, 'month' => 0, 'quarter' => 0];
} else {
    if ($monthOnly !== null) {
        $tasks[] = ['type' => 'month', 'year' => $year, 'month' => $monthOnly, 'quarter' => 0];
    } elseif ($quarterOnly !== null) {
        $tasks[] = ['type' => 'quarter', 'year' => $year, 'month' => 0, 'quarter' => $quarterOnly];
    } else {
        $currentMonth = (int) date('n');
        $currentQuarter = (int) ceil($currentMonth / 3);
        $tasks[] = ['type' => 'month', 'year' => $year, 'month' => $currentMonth, 'quarter' => 0];
        $tasks[] = ['type' => 'quarter', 'year' => $year, 'month' => 0, 'quarter' => $currentQuarter];
        $tasks[] = ['type' => 'year', 'year' => $year, 'month' => 0, 'quarter' => 0];
    }
}

$processed = 0;
$errors = 0;

foreach ($tasks as $task) {
    $type = $task['type'];
    $y = $task['year'];
    $m = (int) ($task['month'] ?? 0);
    $q = (int) ($task['quarter'] ?? 0);

    if ($type === 'month') {
        $laws = $db->getLawsForPeriod('month', $y, $m);
        $label = ($monthNames[$m] ?? '') . ' ' . $y;
        $mOrQ = $m;
    } elseif ($type === 'quarter') {
        $laws = $db->getLawsForPeriod('quarter', $y, $q);
        $label = "Q{$q} {$y}";
        $mOrQ = $q;
    } else {
        $laws = $db->getLawsForPeriod('year', $y, 0);
        $label = "rok {$y}";
        $mOrQ = 0;
    }

    if (empty($laws)) {
        echo "{$label}: žiadne zákony s AI zhrnutím, preskakujem\n";
        @flush();
        continue;
    }

    $summaries = [];
    foreach ($laws as $law) {
        $decoded = json_decode($law['ai_summary'] ?? '{}', true);
        if (!is_array($decoded)) {
            continue;
        }
        $decoded['title'] = $law['title'] ?? '';
        $summaries[] = $decoded;
    }

    if (empty($summaries)) {
        echo "{$label}: žiadne platné zhrnutia, preskakujem\n";
        @flush();
        continue;
    }

    echo "{$label}: spracovávam " . count($summaries) . " zákonov... ";
    @flush();

    try {
        $periodSummary = $aiClient->generatePeriodSummary($summaries, $label);
        $db->savePeriodSummary($type, $y, $mOrQ, $periodSummary, count($summaries));
        $processed++;
        echo "OK\n";
    } catch (\Throwable $e) {
        $logger->error("Period summary {$label}: " . $e->getMessage());
        $errors++;
        echo "CHYBA: " . $e->getMessage() . "\n";
    }
    @flush();
}

echo "\nHotovo. Spracované: {$processed}, chyby: {$errors}\n";
@flush();
