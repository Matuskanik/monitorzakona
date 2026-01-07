#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\Scraper;
use App\DocumentExtractor;
use App\Logger;

echo "=== Sentinel Self-Check ===\n\n";

$checks = [];
$errors = [];

// 1. Check configuration
echo "1. Checking configuration...\n";
try {
    Config::load();
    $checks[] = "✓ Configuration loaded";
} catch (\Exception $e) {
    $errors[] = "✗ Configuration failed: " . $e->getMessage();
    echo "✗ Configuration failed\n";
}

// 2. Check database
echo "2. Checking database...\n";
try {
    $dbPath = Config::get('DB_PATH', 'data/sentinel.db');
    $db = new Database($dbPath);
    $checks[] = "✓ Database initialized";
    
    // Test query
    $db->getPdo()->query("SELECT 1");
    $checks[] = "✓ Database connection works";
} catch (\Exception $e) {
    $errors[] = "✗ Database failed: " . $e->getMessage();
    echo "✗ Database failed\n";
}

// 3. Check logging
echo "3. Checking logging...\n";
try {
    $logPath = Config::get('LOG_PATH', 'storage/logs') . '/app.log';
    $logger = new Logger($logPath);
    $logger->info("Self-check test");
    $checks[] = "✓ Logging works";
} catch (\Exception $e) {
    $errors[] = "✗ Logging failed: " . $e->getMessage();
    echo "✗ Logging failed\n";
}

// 4. Check scraper - fetch list page
echo "4. Checking scraper (fetching list page)...\n";
try {
    if (!isset($logger)) {
        $logger = new Logger(Config::get('LOG_PATH', 'storage/logs') . '/app.log');
    }
    $scraper = new Scraper($logger, 2, 1, 2);
    $listUrl = Config::get('NR_SR_LIST_URL', 'https://www.nrsr.sk/web/default.aspx?SectionId=184');
    $html = $scraper->fetchListPage($listUrl);
    
    if (strlen($html) > 1000) {
        $checks[] = "✓ Can fetch list page";
        
        // Try to parse
        $laws = $scraper->parseListPage($html);
        if (!empty($laws)) {
            $checks[] = "✓ Can parse MasterID (found " . count($laws) . " laws)";
            echo "   Found MasterIDs: " . implode(', ', array_column($laws, 'master_id')) . "\n";
        } else {
            $errors[] = "✗ No MasterIDs found in parsed list";
            echo "   ⚠ No MasterIDs found (may be normal if page structure changed)\n";
        }
    } else {
        $errors[] = "✗ List page too short";
        echo "✗ List page fetch returned too little content\n";
    }
} catch (\Exception $e) {
    $errors[] = "✗ Scraper failed: " . $e->getMessage();
    echo "✗ Scraper failed: " . $e->getMessage() . "\n";
}

// 5. Check document extractor
echo "5. Checking document extractor...\n";
try {
    if (!isset($logger)) {
        $logger = new Logger(Config::get('LOG_PATH', 'storage/logs') . '/app.log');
    }
    $extractor = new DocumentExtractor($logger);
    $checks[] = "✓ Document extractor initialized";
    
    // Check for pdftotext
    $reflection = new \ReflectionClass($extractor);
    $method = $reflection->getMethod('findPdftotext');
    $method->setAccessible(true);
    $pdftotext = $method->invoke($extractor);
    
    if ($pdftotext) {
        $checks[] = "✓ pdftotext found: {$pdftotext}";
    } else {
        $errors[] = "⚠ pdftotext not found (PDF extraction will be skipped)";
        echo "   ⚠ pdftotext not found (install poppler-utils)\n";
    }
    
    // Check for pdftoppm (for OCR)
    $method = $reflection->getMethod('findPdftoppm');
    $method->setAccessible(true);
    $pdftoppm = $method->invoke($extractor);
    
    if ($pdftoppm) {
        $checks[] = "✓ pdftoppm found: {$pdftoppm}";
    } else {
        $errors[] = "⚠ pdftoppm not found (install poppler-utils for OCR)";
        echo "   ⚠ pdftoppm not found (install poppler-utils for OCR)\n";
    }
    
    // Check for Tesseract OCR
    $method = $reflection->getMethod('findTesseract');
    $method->setAccessible(true);
    $tesseract = $method->invoke($extractor);
    
    if ($tesseract) {
        $checks[] = "✓ Tesseract OCR found: {$tesseract}";
        
        // Check for Slovak language support
        exec(escapeshellarg($tesseract) . ' --list-langs 2>&1', $langs, $langCode);
        if ($langCode === 0) {
            $langList = implode(' ', $langs);
            if (stripos($langList, 'slk') !== false || stripos($langList, 'slovak') !== false) {
                $checks[] = "✓ Slovak language (slk) support available";
            } else {
                $errors[] = "⚠ Slovak language (slk) not found. Install: brew install tesseract-lang (macOS)";
                echo "   ⚠ Slovak language support not found (will use default language)\n";
            }
        }
    } else {
        $errors[] = "⚠ Tesseract OCR not found (install: brew install tesseract tesseract-lang)";
        echo "   ⚠ Tesseract OCR not found (install: brew install tesseract tesseract-lang)\n";
    }
} catch (\Exception $e) {
    $errors[] = "✗ Document extractor failed: " . $e->getMessage();
    echo "✗ Document extractor failed\n";
}

// 6. Check OpenAI config
echo "6. Checking OpenAI configuration...\n";
$openaiKey = Config::get('OPENAI_API_KEY');
if (empty($openaiKey) || $openaiKey === 'your_openai_api_key_here') {
    $errors[] = "⚠ OPENAI_API_KEY not configured";
    echo "   ⚠ OPENAI_API_KEY not configured\n";
} else {
    $checks[] = "✓ OpenAI API key configured";
}

// 7. Check storage directories
echo "7. Checking storage directories...\n";
try {
    $storagePath = Config::get('STORAGE_PATH', 'storage');
    $dirs = [
        $storagePath,
        $storagePath . '/logs',
        Config::get('DATA_PATH', 'data')
    ];
    
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (is_writable($dir)) {
            $checks[] = "✓ Directory writable: {$dir}";
        } else {
            $errors[] = "✗ Directory not writable: {$dir}";
            echo "✗ Directory not writable: {$dir}\n";
        }
    }
} catch (\Exception $e) {
    $errors[] = "✗ Storage check failed: " . $e->getMessage();
    echo "✗ Storage check failed\n";
}

// Summary
echo "\n=== Summary ===\n";
echo "Passed checks: " . count($checks) . "\n";
foreach ($checks as $check) {
    echo "  {$check}\n";
}

if (!empty($errors)) {
    echo "\nIssues found: " . count($errors) . "\n";
    foreach ($errors as $error) {
        echo "  {$error}\n";
    }
    exit(1);
} else {
    echo "\n✓ All checks passed!\n";
    exit(0);
}

