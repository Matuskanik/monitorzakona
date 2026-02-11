#!/usr/bin/env php
<?php

/**
 * Znovu spracuje problematické zákony zo Slov-Lexu:
 * 1) Zákony s chybou v processing_log (napr. starý bug getNextSibling)
 * 2) Zákony s príliš krátkym combined.txt (< MIN_CHARS znakov) – pravdepodobne neúplná extrakcia
 *
 * Usage: php retry-slovlex.php [short_threshold] [--dry-run]
 *   short_threshold = min. počet znakov (default 2000). Zákony pod touto hranicou sa znovu stiahnu.
 *   --dry-run = len zobrazí zoznam, nespracuje
 *
 * Spustenie: php bin/retry-slovlex.php 2000
 */

$dryRun = in_array('--dry-run', $argv ?? []);
$argv = array_values(array_filter($argv ?? [], fn($a) => $a !== '--dry-run'));

ob_implicit_flush(1);
if (ob_get_level()) {
    ob_end_flush();
}

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\Logger;
use App\LawChunker;
use App\SlovLexScraper;

$shortThreshold = (isset($argv[1]) && is_numeric($argv[1])) ? (int) $argv[1] : 2000;

try {
    Config::load();
    $dbPath = Config::get('DB_PATH');
    $logPath = Config::get('LOG_PATH', __DIR__ . '/../storage/logs');
    $storagePath = Config::get('STORAGE_PATH', __DIR__ . '/../storage');
} catch (\Exception $e) {
    $dbPath = __DIR__ . '/../data/sentinel.db';
    $logPath = __DIR__ . '/../storage/logs';
    $storagePath = __DIR__ . '/../storage';
}
if (!str_starts_with($storagePath, '/')) {
    $storagePath = dirname(__DIR__) . '/' . $storagePath;
}
$slovLexStorage = $storagePath . '/slovlex_zz';

$logger = new Logger($logPath . '/app.log');
$db = new Database($dbPath ?: 'data/sentinel.db');
$scraper = new SlovLexScraper($logger, 2, 3, 5);

echo "Retry Slov-Lex: short_threshold={$shortThreshold} znakov\n";
@flush();

// 1) Zákony s chybou v processing_log (Slov-Lex)
$errorRows = $db->getPdo()->query("
    SELECT DISTINCT master_id FROM processing_log
    WHERE status = 'error' AND master_id LIKE 'slovlex-ZZ-%'
")->fetchAll(\PDO::FETCH_COLUMN);

// 2) Zákony s krátkym obsahom (súčet chunkov v DB < threshold)
$shortFromDb = $db->getPdo()->query("
    SELECT l.master_id, l.external_id,
           COALESCE(SUM(LENGTH(c.content)), 0) as total_chars
    FROM laws l
    LEFT JOIN law_chunks c ON c.law_id = l.id
    WHERE l.origin = 'slovlex_zz'
    GROUP BY l.id
    HAVING total_chars < " . (int) $shortThreshold . "
")->fetchAll(\PDO::FETCH_ASSOC);

$shortList = [];
foreach ($shortFromDb as $row) {
    $ext = $row['external_id'] ?? '';
    if (preg_match('#^(\d+)/(\d+)$#', $ext, $m)) {
        $shortList[] = ['year' => $m[1], 'number' => $m[2]];
    }
}

$retryByMasterId = [];
foreach ($errorRows as $masterId) {
    if (preg_match('/^slovlex-ZZ-(\d+)-(\d+)$/', $masterId, $m)) {
        $retryByMasterId[$masterId] = ['year' => $m[1], 'number' => $m[2]];
    }
}
foreach ($shortList as $item) {
    $masterId = SlovLexScraper::masterId($item['year'], $item['number']);
    if (!isset($retryByMasterId[$masterId])) {
        $retryByMasterId[$masterId] = $item;
    }
}

$toRetry = array_values($retryByMasterId);
$total = count($toRetry);

echo "Na znovu spracovanie: {$total} zákonov (chyby: " . count($errorRows) . ", krátke: " . count($shortList) . ")\n\n";
@flush();

if ($total === 0) {
    echo "Nič na spracovanie.\n";
    exit(0);
}

if ($dryRun) {
    echo "(--dry-run) Prvých 20: " . implode(', ', array_slice(array_map(fn($i) => $i['year'].'/'.$i['number'], $toRetry), 0, 20)) . "\n";
    exit(0);
}

$processed = 0;
$stillShort = 0;
$errors = 0;

foreach ($toRetry as $i => $item) {
    $yearNum = $item['year'];
    $number = $item['number'];
    $masterId = SlovLexScraper::masterId($yearNum, $number);

    try {
        $html = $scraper->fetchLawHtml($yearNum, $number);
        $extracted = $scraper->extractTextAndMetadata($html);
        $text = $extracted['text'];

        if (mb_strlen($text) < 50) {
            echo "s"; // still too short
            $stillShort++;
            $logger->warning("Retry SlovLex {$masterId}: stále krátky text po re-extrakcii");
            continue;
        }

        $contentHash = hash('sha256', $text);
        $approvalDate = $extracted['approval_date'] ?? $extracted['publication_date'] ?? null;
        $displayTitle = $extracted['title'] ?? $yearNum . '/' . $number . ' Z. z.';
        $tags = $extracted['tags'];
        $aiSummaryJson = !empty($tags) ? json_encode(['tags' => $tags], JSON_UNESCAPED_UNICODE) : null;

        $dir = $slovLexStorage . '/' . $yearNum . '/' . $number;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($dir . '/combined.txt', $text);

        $lawId = $db->saveLaw([
            'master_id' => $masterId,
            'title' => $displayTitle,
            'approval_date' => $approvalDate,
            'source_url' => SlovLexScraper::sourceUrl($yearNum, $number),
            'content_hash' => $contentHash,
            'ai_summary' => $aiSummaryJson,
            'processing_status' => 'completed',
            'text_extracted' => true,
            'origin' => 'slovlex_zz',
            'external_id' => $yearNum . '/' . $number,
        ]);
        $db->deleteChunksByLawId($lawId);
        foreach (LawChunker::chunk($text) as $idx => $c) {
            $db->saveLawChunk($lawId, $idx, $c['content'], $c['char_start'], $c['char_end'], $c['section_title'] ?? null);
        }
        $db->logProcessing($masterId, 'success', 'Slov-Lex retry');

        $processed++;
        echo ".";
    } catch (\Throwable $e) {
        $logger->error("Retry SlovLex {$masterId}: " . $e->getMessage());
        $db->logProcessing($masterId, 'error', $e->getMessage());
        $errors++;
        echo "E";
    }

    if (($i + 1) % 50 === 0) {
        echo " [{$i}/{$total}] ";
        @flush();
    }
    @flush();
}

echo "\n\nHotovo. Spracované: {$processed}, stále krátke: {$stillShort}, chyby: {$errors}\n";
@flush();
