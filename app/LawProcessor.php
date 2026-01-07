<?php

namespace App;

class LawProcessor
{
    private Scraper $scraper;
    private DocumentExtractor $extractor;
    private OpenAIClient $aiClient;
    private Database $db;
    private Logger $logger;
    private string $storagePath;

    public function __construct(
        Scraper $scraper,
        DocumentExtractor $extractor,
        OpenAIClient $aiClient,
        Database $db,
        Logger $logger,
        string $storagePath
    ) {
        $this->scraper = $scraper;
        $this->extractor = $extractor;
        $this->aiClient = $aiClient;
        $this->db = $db;
        $this->logger = $logger;
        $this->storagePath = $storagePath;
    }

    public function processLaw(array $lawData): bool
    {
        $masterId = $lawData['master_id'];
        $this->logger->info("Processing law: MasterID {$masterId}");

        try {
            // Check if already processed
            $existing = $this->db->findLawByMasterId($masterId);
            
            // Fetch detail page
            $detailHtml = $this->scraper->fetchDetailPage($lawData['url']);
            
            // Save HTML snapshot
            $lawStorageDir = $this->storagePath . '/' . $masterId;
            if (!is_dir($lawStorageDir)) {
                mkdir($lawStorageDir, 0755, true);
            }
            file_put_contents($lawStorageDir . '/source_detail.html', $detailHtml);

            // Extract delivery date from detail page if not already set
            if (empty($lawData['approval_date'])) {
                $deliveryDate = $this->scraper->extractDeliveryDate($detailHtml);
                if ($deliveryDate) {
                    $lawData['approval_date'] = $deliveryDate;
                    $this->logger->info("Extracted delivery date for MasterID {$masterId}: {$deliveryDate}");
                }
            }

            // Parse attachments
            $attachments = $this->scraper->parseDetailPage($detailHtml, $masterId);
            
            if (empty($attachments)) {
                $this->logger->warning("No attachments found for MasterID {$masterId}");
                $this->db->logProcessing($masterId, 'no_attachments');
                return false;
            }

            // Download attachments
            $attachmentDir = $lawStorageDir . '/attachments';
            if (!is_dir($attachmentDir)) {
                mkdir($attachmentDir, 0755, true);
            }

            $downloadedFiles = [];
            $texts = [];

            // Prefer ZIP if available
            $zipAttachment = null;
            foreach ($attachments as $att) {
                if ($att['is_zip'] ?? false) {
                    $zipAttachment = $att;
                    break;
                }
            }

            if ($zipAttachment) {
                $zipPath = $attachmentDir . '/' . $zipAttachment['filename'];
                $this->scraper->downloadFile($zipAttachment['url'], $zipPath);
                $extractedFiles = $this->extractor->extractFromZip($zipPath, $attachmentDir);
                
                foreach ($extractedFiles as $file) {
                    $downloadedFiles[] = $file['path'];
                    try {
                        if ($file['type'] === 'docx') {
                            $texts[] = $this->extractor->extractFromDocx($file['path']);
                        } elseif ($file['type'] === 'pdf') {
                            $pdfText = $this->extractor->extractFromPdf($file['path']);
                            if (!empty($pdfText)) {
                                $texts[] = $pdfText;
                            }
                        }
                    } catch (\Exception $e) {
                        $this->logger->error("Failed to extract text from {$file['path']}: " . $e->getMessage());
                    }
                }
            } else {
                // Download individual files
                foreach ($attachments as $att) {
                    $filePath = $attachmentDir . '/' . $att['filename'];
                    $this->scraper->downloadFile($att['url'], $filePath);
                    $downloadedFiles[] = $filePath;
                    
                    try {
                        if ($att['type'] === 'docx') {
                            $texts[] = $this->extractor->extractFromDocx($filePath);
                        } elseif ($att['type'] === 'pdf') {
                            $pdfText = $this->extractor->extractFromPdf($filePath);
                            if (!empty($pdfText)) {
                                $texts[] = $pdfText;
                                $this->logger->info("Successfully extracted text from PDF: {$filePath}");
                            } else {
                                $this->logger->warning("No text extracted from PDF (may require OCR): {$filePath}");
                            }
                        }
                    } catch (\Exception $e) {
                        $this->logger->error("Failed to extract text from {$filePath}: " . $e->getMessage());
                    }
                }
            }

            // Handle case where no text was extracted (e.g., image-based PDFs)
            if (empty($texts)) {
                $this->logger->warning("No text extracted for MasterID {$masterId} even after OCR attempt");
                
                // Still save the law record but mark it as failed
                $contentHash = hash('sha256', $masterId . $lawData['url'] . time()); // Use unique hash
                
                $aiSummaryJson = json_encode([
                    'summary_paragraph' => 'Text z tohto zákona sa nepodarilo extrahovať ani pomocou OCR technológie. Dokumenty môžu byť poškodené, príliš nízkej kvality, alebo v nepodporovanom formáte.',
                    'affected_groups' => ['Informácie nie sú dostupné'],
                    'positives' => [],
                    'negatives' => ['Nepodarilo sa extrahovať text z dokumentov'],
                    'how_to_react' => ['Pre analýzu zákona je potrebné manuálne prečítať dokumenty z NR SR zdroja'],
                    'disclaimer' => 'Toto nie je právne poradenstvo. Informácie sú len informatívneho charakteru. Pre právne poradenstvo sa obráťte na kvalifikovaného právnika.'
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                
                $lawId = $this->db->saveLaw([
                    'master_id' => $masterId,
                    'title' => $lawData['title'],
                    'approval_date' => $lawData['approval_date'],
                    'source_url' => $lawData['url'],
                    'content_hash' => $contentHash,
                    'ai_summary' => $aiSummaryJson,
                    'processing_status' => 'extraction_failed',
                    'text_extracted' => false
                ]);
                
                // Save attachments
                foreach ($downloadedFiles as $filePath) {
                    $filename = basename($filePath);
                    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                    
                    $sourceUrl = null;
                    if ($zipAttachment) {
                        $sourceUrl = $zipAttachment['url'];
                    } else {
                        foreach ($attachments as $att) {
                            if (basename($att['filename']) === $filename) {
                                $sourceUrl = $att['url'];
                                break;
                            }
                        }
                    }

                    $this->db->saveAttachment($lawId, [
                        'filename' => $filename,
                        'filepath' => $filePath,
                        'source_url' => $sourceUrl,
                        'file_type' => $ext
                    ]);
                }
                
                $this->db->logProcessing($masterId, 'extraction_failed', 'Text extraction failed even with OCR');
                $this->logger->info("Saved law MasterID {$masterId} with extraction-failed status");
                return true;
            }

            // Combine texts
            $combinedText = $this->extractor->combineTexts($texts);
            file_put_contents($lawStorageDir . '/combined.txt', $combinedText);

            // Calculate content hash
            $contentHash = hash('sha256', $combinedText);

            // Check if content changed or if AI summary is missing (needs reprocessing)
            if ($existing && $existing['content_hash'] === $contentHash && !empty($existing['ai_summary'])) {
                $this->logger->info("Law MasterID {$masterId} unchanged, skipping AI processing");
                $this->db->logProcessing($masterId, 'unchanged');
                return true;
            }
            
            // If content hash matches but AI summary is missing, reprocess
            if ($existing && $existing['content_hash'] === $contentHash && empty($existing['ai_summary'])) {
                $this->logger->info("Law MasterID {$masterId} content unchanged but AI summary missing, reprocessing");
            }

            // Generate AI summary
            $aiSummary = $this->aiClient->generateSummary($combinedText);
            $aiSummaryJson = json_encode($aiSummary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

            // Save to database
            $lawId = $this->db->saveLaw([
                'master_id' => $masterId,
                'title' => $lawData['title'],
                'approval_date' => $lawData['approval_date'],
                'source_url' => $lawData['url'],
                'content_hash' => $contentHash,
                'ai_summary' => $aiSummaryJson,
                'processing_status' => 'completed',
                'text_extracted' => true
            ]);

            // Save attachments
            foreach ($downloadedFiles as $filePath) {
                $filename = basename($filePath);
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                
                // Find original attachment URL
                $sourceUrl = null;
                if ($zipAttachment) {
                    // If extracted from ZIP, use ZIP URL as source
                    $sourceUrl = $zipAttachment['url'];
                } else {
                    // Find matching attachment URL
                    foreach ($attachments as $att) {
                        if (basename($att['filename']) === $filename) {
                            $sourceUrl = $att['url'];
                            break;
                        }
                    }
                }

                $this->db->saveAttachment($lawId, [
                    'filename' => $filename,
                    'filepath' => $filePath,
                    'source_url' => $sourceUrl,
                    'file_type' => $ext
                ]);
            }

            $this->db->logProcessing($masterId, 'success', "Processed successfully");
            $this->logger->info("Successfully processed law MasterID {$masterId}");
            return true;

        } catch (\Exception $e) {
            $this->logger->error("Failed to process law MasterID {$masterId}: " . $e->getMessage());
            $this->db->logProcessing($masterId, 'error', $e->getMessage());
            return false;
        }
    }
}

