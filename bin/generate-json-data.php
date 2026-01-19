#!/usr/bin/env php
<?php

/**
 * Generate JSON data files from SQLite database
 * Creates data/laws/*.json files and data/index.json
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;

try {
    Config::load();
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

$db = new Database(Config::get('DB_PATH'));
$pdo = $db->getPdo();

// Get all processed laws (with ai_summary) chronologically from newest
$stmt = $pdo->query("
    SELECT l.*, 
           GROUP_CONCAT(
               json_object(
                   'filename', a.filename,
                   'source_url', a.source_url,
                   'file_type', a.file_type
               )
           ) as attachments_json
    FROM laws l
    LEFT JOIN attachments a ON a.law_id = l.id
    WHERE l.ai_summary IS NOT NULL AND l.ai_summary != ''
    GROUP BY l.id
    ORDER BY 
        CASE 
            WHEN l.approval_date IS NOT NULL AND l.approval_date != '' THEN
                substr('0000' || substr(l.approval_date, -4), -4) || '-' ||
                substr('00' || substr(l.approval_date, length(l.approval_date) - 7, 2), -2) || '-' ||
                substr('00' || substr(l.approval_date, 1, 2), -2)
            ELSE '0000-00-00'
        END DESC,
        l.created_at DESC
");

$laws = $stmt->fetchAll(\PDO::FETCH_ASSOC);

// Create public/data/laws directory (for Cloudflare Pages)
$lawsDir = __DIR__ . '/../public/data/laws';
if (!is_dir($lawsDir)) {
    mkdir($lawsDir, 0755, true);
}

$indexData = [];

foreach ($laws as $law) {
    // Parse AI summary
    $summary = null;
    if (!empty($law['ai_summary'])) {
        $summary = json_decode($law['ai_summary'], true);
    }
    
    // Parse attachments
    $attachments = [];
    if (!empty($law['attachments_json'])) {
        // SQLite GROUP_CONCAT creates comma-separated JSON objects
        // We need to parse them properly
        $attachmentsRaw = explode(',', $law['attachments_json']);
        foreach ($attachmentsRaw as $attJson) {
            $att = json_decode($attJson, true);
            if ($att) {
                $attachments[] = $att;
            }
        }
    }
    
    // Create law data structure
    $lawData = [
        'id' => (int)$law['id'],
        'master_id' => $law['master_id'],
        'title' => $law['title'],
        'approval_date' => $law['approval_date'],
        'source_url' => $law['source_url'],
        'created_at' => $law['created_at'],
        'updated_at' => $law['updated_at'],
        'summary' => $summary,
        'attachments' => $attachments,
        'processing_status' => $law['processing_status'] ?? 'completed',
        'text_extracted' => (bool)($law['text_extracted'] ?? 1)
    ];
    
    // Save individual law JSON file
    $lawFile = $lawsDir . '/' . $law['master_id'] . '.json';
    file_put_contents(
        $lawFile,
        json_encode($lawData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
    
    // Add to index (lightweight version)
    $indexData[] = [
        'id' => (int)$law['id'],
        'master_id' => $law['master_id'],
        'title' => $law['title'],
        'approval_date' => $law['approval_date'],
        'source_url' => $law['source_url'],
        'created_at' => $law['created_at'],
        'tags' => $summary['tags'] ?? [],
        'summary_preview' => isset($summary['summary_paragraph']) 
            ? mb_substr($summary['summary_paragraph'], 0, 200) . '...' 
            : null
    ];
}

// Save index.json in public/data (for Cloudflare Pages)
$indexFile = __DIR__ . '/../public/data/index.json';
file_put_contents(
    $indexFile,
    json_encode($indexData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);

echo "Generated " . count($laws) . " law JSON files and index.json\n";
echo "Laws directory: {$lawsDir}\n";
echo "Index file: {$indexFile}\n";
