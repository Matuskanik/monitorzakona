#!/usr/bin/env php
<?php

/**
 * Chunk all laws that have text but no chunks (or re-chunk all).
 * Reads text from storage (NR SR: storage/{master_id}/combined.txt; Slov-Lex: storage/slovlex_zz/{year}/{number}/combined.txt).
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\LawChunker;

$dbPath = Config::get('DB_PATH', 'data/sentinel.db') ?: 'data/sentinel.db';
$storagePath = Config::get('STORAGE_PATH', __DIR__ . '/../storage');
if (!str_starts_with($storagePath, '/')) {
    $storagePath = dirname(__DIR__) . '/' . $storagePath;
}

$db = new Database($dbPath);
$laws = $db->getLatestLaws(10000, null);
$chunked = 0;
$skipped = 0;
$errors = 0;

foreach ($laws as $law) {
    $lawId = (int) $law['id'];
    $masterId = $law['master_id'] ?? '';
    $origin = $law['origin'] ?? 'nrsr';
    $externalId = $law['external_id'] ?? '';

    $combinedPath = null;
    if ($origin === 'slovlex_zz') {
        $extId = $externalId ?: (preg_match('/^slovlex-ZZ-(\d+)-(\d+)$/', $masterId, $m) ? $m[1] . '/' . $m[2] : null);
        if ($extId) {
            $combinedPath = $storagePath . '/slovlex_zz/' . str_replace('\\', '/', $extId) . '/combined.txt';
        }
    } else {
        $combinedPath = $storagePath . '/' . $masterId . '/combined.txt';
    }
    if (!$combinedPath || !is_readable($combinedPath)) {
        $skipped++;
        continue;
    }
    $text = file_get_contents($combinedPath);
    if (trim($text) === '') {
        $skipped++;
        continue;
    }
    try {
        $db->deleteChunksByLawId($lawId);
        foreach (LawChunker::chunk($text) as $idx => $c) {
            $db->saveLawChunk($lawId, $idx, $c['content'], $c['char_start'], $c['char_end'], $c['section_title'] ?? null);
        }
        $chunked++;
        echo ".";
    } catch (\Throwable $e) {
        $errors++;
        echo "E";
    }
}

echo "\nChunked: {$chunked}, skipped: {$skipped}, errors: {$errors}\n";
