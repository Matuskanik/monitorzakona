#!/usr/bin/env php
<?php

// Simple script to clear AI summary from latest law to force reprocessing
$dbPath = __DIR__ . '/../data/sentinel.db';

if (!file_exists($dbPath)) {
    $dbPath = __DIR__ . '/../public/data/sentinel.db';
}

if (!file_exists($dbPath)) {
    die("Database not found. Tried: {$dbPath}\n");
}

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get latest law
    $stmt = $pdo->query("SELECT id, master_id, title FROM laws ORDER BY created_at DESC LIMIT 1");
    $law = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$law) {
        die("No laws found in database.\n");
    }
    
    echo "Found law:\n";
    echo "  ID: {$law['id']}\n";
    echo "  MasterID: {$law['master_id']}\n";
    echo "  Title: {$law['title']}\n\n";
    
    // Clear AI summary
    $stmt = $pdo->prepare("UPDATE laws SET ai_summary = NULL, processing_status = 'pending' WHERE id = ?");
    $stmt->execute([$law['id']]);
    
    echo "✓ AI summary cleared for law ID {$law['id']}\n";
    echo "\nNow run: php bin/cron.php\n";
    echo "Or visit: http://localhost:8000/law.php?id={$law['id']}\n";
    
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage() . "\n");
}

