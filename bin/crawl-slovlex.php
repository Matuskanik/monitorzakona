#!/usr/bin/env php
<?php

/**
 * Crawl static.slov-lex.sk Zbierka zákonov (ZZ), fetch vyhlásené znenie HTML,
 * extract text and metadata, save to storage and DB (origin=slovlex_zz).
 *
 * Usage: php crawl-slovlex.php [years_limit]
 *   years_limit = number of years to crawl (default 2). E.g. 5 = last 5 years.
 *   To crawl all years, pass a large number (e.g. 100).
 *
 * Spustenie v termináli (výstup v reálnom čase): php bin/crawl-slovlex.php 120
 */

// Okamžité zobrazenie výstupu v termináli (bez buffrovania)
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

$dbPath = null;
$logPath = null;
$storagePath = __DIR__ . '/../storage';
$yearsLimit = 2;

try {
    Config::load();
    $dbPath = Config::get('DB_PATH');
    $logPath = Config::get('LOG_PATH', __DIR__ . '/../storage/logs');
    $storagePath = Config::get('STORAGE_PATH', __DIR__ . '/../storage');
} catch (\Exception $e) {
    $dbPath = __DIR__ . '/../data/sentinel.db';
    if (!file_exists($dbPath)) {
        $dbPath = __DIR__ . '/../public/data/sentinel.db';
    }
    $logPath = __DIR__ . '/../storage/logs';
    if (!is_dir($logPath)) {
        @mkdir($logPath, 0755, true);
    }
}
if (isset($argv[1]) && is_numeric($argv[1])) {
    $yearsLimit = (int) $argv[1];
}
if (!str_starts_with($storagePath, '/')) {
    $storagePath = dirname(__DIR__) . '/' . $storagePath;
}
$slovLexStorage = $storagePath . '/slovlex_zz';

$logger = new Logger($logPath . '/app.log');
$db = new Database($dbPath ?: 'data/sentinel.db');
$scraper = new SlovLexScraper($logger, 2, 3, 5);

// Debug: vypíš absolútne cesty
$resolvedDbPath = $db->getPdo()->query("PRAGMA database_list")->fetchAll()[0]['file'] ?? 'unknown';
echo "=== DEBUG: Cesty ===\n";
echo "DB_PATH (config): " . ($dbPath ?: 'data/sentinel.db') . "\n";
echo "DB_PATH (resolved): {$resolvedDbPath}\n";
echo "STORAGE_PATH: {$storagePath}\n";
echo "SLOVLEX_STORAGE: {$slovLexStorage}\n";
echo "===================\n";
@flush();

echo "Slov-Lex crawl: years_limit={$yearsLimit}, storage={$slovLexStorage}\n";
@flush();

$years = $scraper->fetchYears();
$years = array_slice($years, 0, $yearsLimit);
echo "Crawling " . count($years) . " years: " . implode(', ', $years) . "\n\n";
@flush();

$totalProcessed = 0;
$totalSkipped = 0;
$totalErrors = 0;

foreach ($years as $year) {
    $acts = $scraper->fetchYearIndex($year);
    $totalInYear = count($acts);
    echo "Rok {$year} ({$totalInYear} zákonov): ";
    @flush();

    $doneInYear = 0;
    foreach ($acts as $act) {
        $yearNum = $act['year'];
        $number = $act['number'];
        $title = $act['title'];
        $masterId = SlovLexScraper::masterId($yearNum, $number);
        $externalId = $yearNum . '/' . $number;

        try {
            $existing = $db->findLawByMasterId($masterId);
            $html = $scraper->fetchLawHtml($yearNum, $number);
            $extracted = $scraper->extractTextAndMetadata($html);
            $text = $extracted['text'];
            if (mb_strlen($text) < 50) {
                $logger->warning("SlovLex {$masterId}: extracted text too short, skipping");
                $totalSkipped++;
                $doneInYear++;
                if ($doneInYear % 50 === 0 && $doneInYear < $totalInYear) {
                    echo " [{$doneInYear}/{$totalInYear}] ";
                    @flush();
                }
                continue;
            }
            $contentHash = hash('sha256', $text);
            if ($existing && ($existing['content_hash'] ?? '') === $contentHash) {
                $totalSkipped++;
                $doneInYear++;
                if ($doneInYear % 50 === 0 && $doneInYear < $totalInYear) {
                    echo " [{$doneInYear}/{$totalInYear}] ";
                    @flush();
                }
                continue;
            }

            $approvalDate = $extracted['approval_date'] ?? $extracted['publication_date'] ?? null;
            $displayTitle = $extracted['title'] ?: $title;
            $tags = $extracted['tags'];
            $aiSummaryJson = null;
            if (!empty($tags)) {
                $aiSummaryJson = json_encode(['tags' => $tags], JSON_UNESCAPED_UNICODE);
            }

            $dir = $slovLexStorage . '/' . $yearNum . '/' . $number;
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $combinedPath = $dir . '/combined.txt';
            file_put_contents($combinedPath, $text);

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
                'external_id' => $externalId,
            ]);
            $db->deleteChunksByLawId($lawId);
            foreach (LawChunker::chunk($text) as $idx => $c) {
                $db->saveLawChunk($lawId, $idx, $c['content'], $c['char_start'], $c['char_end'], $c['section_title'] ?? null);
            }
            $db->logProcessing($masterId, 'success', 'Slov-Lex crawl');
            $totalProcessed++;
            echo ".";
        } catch (\Throwable $e) {
            $logger->error("SlovLex {$masterId}: " . $e->getMessage());
            $db->logProcessing($masterId, 'error', $e->getMessage());
            $totalErrors++;
            echo "E";
        }
        $doneInYear++;
        if ($doneInYear % 50 === 0 && $doneInYear < $totalInYear) {
            echo " [{$doneInYear}/{$totalInYear}] ";
            @flush();
        }
    }
    echo " [{$totalInYear}/{$totalInYear}]\n";
    @flush();
}

echo "\nHotovo. Spracované: {$totalProcessed}, preskočené: {$totalSkipped}, chyby: {$totalErrors}\n";
@flush();
