<?php

namespace App;

class DocumentExtractor
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function extractFromDocx(string $filePath): string
    {
        $this->logger->info("Extracting text from DOCX: {$filePath}");
        
        if (!file_exists($filePath)) {
            throw new \RuntimeException("DOCX file not found: {$filePath}");
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException("Failed to open DOCX as ZIP: {$filePath}");
        }

        $xmlContent = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xmlContent === false) {
            throw new \RuntimeException("Failed to extract word/document.xml from DOCX");
        }

        // Remove XML tags and decode entities
        $text = strip_tags($xmlContent);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        
        // Normalize whitespace
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);

        $this->logger->info("Extracted " . strlen($text) . " characters from DOCX");
        return $text;
    }

    public function extractFromPdf(string $filePath): string
    {
        $this->logger->info("Extracting text from PDF: {$filePath}");
        
        if (!file_exists($filePath)) {
            throw new \RuntimeException("PDF file not found: {$filePath}");
        }

        // Step 1: Try normal text extraction first
        $pdftotextPath = $this->findPdftotext();
        if ($pdftotextPath) {
            $tempOutput = tempnam(sys_get_temp_dir(), 'pdf_extract_');
            $command = escapeshellarg($pdftotextPath) . ' -layout ' . escapeshellarg($filePath) . ' ' . escapeshellarg($tempOutput) . ' 2>&1';
            
            exec($command, $output, $returnCode);

            if ($returnCode === 0 && file_exists($tempOutput)) {
                $text = file_get_contents($tempOutput);
                unlink($tempOutput);

                // Normalize whitespace
                $text = preg_replace('/\s+/u', ' ', $text);
                $text = trim($text);
                
                // Check if text is mostly whitespace/control characters (likely scanned/image PDF)
                $nonWhitespace = preg_replace('/[\s\x00-\x1F\x7F]/u', '', $text);
                if (strlen($nonWhitespace) >= 10) {
                    $this->logger->info("Extracted " . strlen($text) . " characters from PDF (text-based)");
                    return $text;
                }
                
                $this->logger->info("PDF appears to be image-based, attempting OCR...");
            }
        }

        // Step 2: If normal extraction failed or returned mostly whitespace, try OCR
        return $this->extractFromPdfWithOCR($filePath);
    }

    private function extractFromPdfWithOCR(string $filePath): string
    {
        $this->logger->info("Attempting OCR extraction from PDF: {$filePath}");
        
        // Check if Tesseract is available
        $tesseractPath = $this->findTesseract();
        if (!$tesseractPath) {
            $this->logger->warning("Tesseract OCR not found. Install it: brew install tesseract (macOS) or apt-get install tesseract-ocr (Linux)");
            return "";
        }

        // Check if pdftoppm is available (for converting PDF to images)
        $pdftoppmPath = $this->findPdftoppm();
        if (!$pdftoppmPath) {
            $this->logger->warning("pdftoppm not found (part of poppler-utils). OCR extraction skipped.");
            return "";
        }

        $tempDir = sys_get_temp_dir() . '/pdf_ocr_' . uniqid();
        if (!mkdir($tempDir, 0755, true)) {
            $this->logger->error("Failed to create temp directory for OCR: {$tempDir}");
            return "";
        }

        try {
            // Convert PDF pages to images
            $this->logger->info("Converting PDF pages to images...");
            $imagePrefix = $tempDir . '/page';
            $command = escapeshellarg($pdftoppmPath) . ' -png -r 300 ' . escapeshellarg($filePath) . ' ' . escapeshellarg($imagePrefix) . ' 2>&1';
            
            exec($command, $output, $returnCode);
            
            if ($returnCode !== 0) {
                $error = implode("\n", $output);
                $this->logger->warning("pdftoppm failed: {$error}");
                $this->cleanupTempDir($tempDir);
                return "";
            }

            // Find all generated image files
            $imageFiles = glob($imagePrefix . '*.png');
            if (empty($imageFiles)) {
                $this->logger->warning("No images generated from PDF");
                $this->cleanupTempDir($tempDir);
                return "";
            }

            $this->logger->info("Found " . count($imageFiles) . " pages to OCR");

            // Run OCR on each image
            $allText = [];
            foreach ($imageFiles as $imageFile) {
                $pageText = $this->runOCR($tesseractPath, $imageFile);
                if (!empty($pageText)) {
                    $allText[] = $pageText;
                }
            }

            $this->cleanupTempDir($tempDir);

            if (empty($allText)) {
                $this->logger->warning("OCR extraction produced no text");
                return "";
            }

            // Combine all pages
            $combinedText = implode("\n\n--- Page Break ---\n\n", $allText);
            $combinedText = preg_replace('/\s+/u', ' ', $combinedText);
            $combinedText = trim($combinedText);

            $this->logger->info("OCR extracted " . strlen($combinedText) . " characters from PDF");
            return $combinedText;

        } catch (\Exception $e) {
            $this->logger->error("OCR extraction failed: " . $e->getMessage());
            $this->cleanupTempDir($tempDir);
            return "";
        }
    }

    private function runOCR(string $tesseractPath, string $imagePath): string
    {
        $tempOutput = tempnam(sys_get_temp_dir(), 'ocr_output_');
        
        // Check if Slovak language is available
        $slkAvailable = false;
        exec(escapeshellarg($tesseractPath) . ' --list-langs 2>&1', $langOutput, $langReturn);
        if ($langReturn === 0) {
            $langs = implode(' ', $langOutput);
            $slkAvailable = (stripos($langs, 'slk') !== false);
        }
        
        // Try Slovak first if available, fallback to default
        $langParam = $slkAvailable ? '-l slk' : '';
        $command = escapeshellarg($tesseractPath) . ' ' . escapeshellarg($imagePath) . ' ' . escapeshellarg($tempOutput) . ($langParam ? ' ' . $langParam : '') . ' 2>&1';
        exec($command, $output, $returnCode);
        
        // If Slovak failed, try without language specification
        if ($returnCode !== 0 && $slkAvailable) {
            $this->logger->debug("Slovak OCR failed, trying default language");
            $command = escapeshellarg($tesseractPath) . ' ' . escapeshellarg($imagePath) . ' ' . escapeshellarg($tempOutput) . ' 2>&1';
            exec($command, $output, $returnCode);
        }

        $text = "";
        if ($returnCode === 0 && file_exists($tempOutput . '.txt')) {
            $text = file_get_contents($tempOutput . '.txt');
            unlink($tempOutput . '.txt');
        } else {
            $error = implode("\n", $output);
            $this->logger->warning("OCR failed for image: {$error}");
        }

        // Clean up any remaining temp files
        if (file_exists($tempOutput)) {
            @unlink($tempOutput);
        }
        foreach (glob($tempOutput . '.*') as $file) {
            @unlink($file);
        }

        return trim($text);
    }

    private function findTesseract(): ?string
    {
        $paths = [
            'tesseract',
            '/usr/local/bin/tesseract',
            '/opt/homebrew/bin/tesseract',
            '/usr/bin/tesseract'
        ];
        
        foreach ($paths as $path) {
            $output = [];
            $returnCode = 0;
            exec("which " . escapeshellarg($path) . " 2>&1", $output, $returnCode);
            if ($returnCode === 0 && !empty($output)) {
                $fullPath = trim($output[0]);
                // Verify it's actually tesseract
                exec(escapeshellarg($fullPath) . ' --version 2>&1', $versionOutput, $versionCode);
                if ($versionCode === 0) {
                    return $fullPath;
                }
            }
        }
        
        return null;
    }

    private function findPdftoppm(): ?string
    {
        $paths = [
            'pdftoppm',
            '/usr/local/bin/pdftoppm',
            '/opt/homebrew/bin/pdftoppm',
            '/usr/bin/pdftoppm'
        ];
        
        foreach ($paths as $path) {
            $output = [];
            $returnCode = 0;
            exec("which " . escapeshellarg($path) . " 2>&1", $output, $returnCode);
            if ($returnCode === 0 && !empty($output)) {
                return trim($output[0]);
            }
        }
        
        return null;
    }

    private function cleanupTempDir(string $dir): void
    {
        if (is_dir($dir)) {
            $files = glob($dir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($dir);
        }
    }

    public function extractFromZip(string $filePath, string $outputDir): array
    {
        $this->logger->info("Extracting ZIP: {$filePath}");
        
        if (!file_exists($filePath)) {
            throw new \RuntimeException("ZIP file not found: {$filePath}");
        }
        
        // Verify it's actually a ZIP file by checking magic bytes
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Cannot read file: {$filePath}");
        }
        $magicBytes = fread($handle, 4);
        fclose($handle);
        
        // ZIP files start with PK\x03\x04 or PK\x05\x06 (empty) or PK\x07\x08 (spanned)
        if (substr($magicBytes, 0, 2) !== 'PK') {
            throw new \RuntimeException("File is not a valid ZIP archive: {$filePath}");
        }
        
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $zip = new \ZipArchive();
        $result = $zip->open($filePath);
        if ($result !== true) {
            throw new \RuntimeException("Failed to open ZIP: {$filePath} (error code: {$result})");
        }

        $extractedFiles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if ($filename === false) {
                continue;
            }

            // Skip directories
            if (substr($filename, -1) === '/') {
                continue;
            }

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($ext, ['docx', 'pdf', 'doc'])) {
                continue;
            }

            $targetPath = $outputDir . '/' . basename($filename);
            if ($zip->extractTo($outputDir, $filename)) {
                if (file_exists($outputDir . '/' . $filename)) {
                    rename($outputDir . '/' . $filename, $targetPath);
                }
                $extractedFiles[] = [
                    'path' => $targetPath,
                    'type' => $ext,
                    'original_name' => $filename
                ];
            }
        }

        $zip->close();
        $this->logger->info("Extracted " . count($extractedFiles) . " files from ZIP");
        return $extractedFiles;
    }

    public function combineTexts(array $texts): string
    {
        $combined = implode("\n\n---\n\n", array_filter($texts));
        return trim($combined);
    }

    private function findPdftotext(): ?string
    {
        $paths = ['pdftotext', '/usr/bin/pdftotext', '/usr/local/bin/pdftotext'];
        
        foreach ($paths as $path) {
            $output = [];
            $returnCode = 0;
            exec("which " . escapeshellarg($path) . " 2>&1", $output, $returnCode);
            if ($returnCode === 0 && !empty($output)) {
                return trim($output[0]);
            }
        }
        
        return null;
    }
}

