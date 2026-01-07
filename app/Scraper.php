<?php

namespace App;

class Scraper
{
    private Logger $logger;
    private int $delaySeconds;
    private int $maxRetries;
    private int $retryDelaySeconds;

    public function __construct(Logger $logger, int $delaySeconds = 3, int $maxRetries = 3, int $retryDelaySeconds = 5)
    {
        $this->logger = $logger;
        $this->delaySeconds = $delaySeconds;
        $this->maxRetries = $maxRetries;
        $this->retryDelaySeconds = $retryDelaySeconds;
    }

    private function makeRequest(string $url, array $options = []): string
    {
        $ch = curl_init();
        
        // SSL certificate handling for macOS
        $sslOptions = [];
        $certPaths = [
            '/opt/homebrew/etc/openssl@3/cert.pem',
            '/usr/local/etc/openssl@3/cert.pem',
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt'
        ];
        
        $certPath = null;
        foreach ($certPaths as $path) {
            if (file_exists($path)) {
                $certPath = $path;
                break;
            }
        }
        
        if ($certPath) {
            $sslOptions[CURLOPT_CAINFO] = $certPath;
        }
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            ...$sslOptions,
            ...$options
        ]);

        $attempt = 0;
        while ($attempt < $this->maxRetries) {
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            if ($response === false || !empty($error)) {
                $attempt++;
                $this->logger->warning("Request failed (attempt {$attempt}/{$this->maxRetries}): {$error}");
                if ($attempt < $this->maxRetries) {
                    sleep($this->retryDelaySeconds * $attempt);
                    continue;
                }
                curl_close($ch);
                throw new \RuntimeException("Failed to fetch {$url}: {$error}");
            }

            if ($httpCode >= 200 && $httpCode < 300) {
                curl_close($ch);
                return $response;
            }

            $attempt++;
            $this->logger->warning("HTTP {$httpCode} (attempt {$attempt}/{$this->maxRetries})");
            if ($attempt < $this->maxRetries) {
                sleep($this->retryDelaySeconds * $attempt);
            }
        }

        curl_close($ch);
        throw new \RuntimeException("Failed to fetch {$url}: HTTP {$httpCode}");
    }

    public function fetchListPage(string $url): string
    {
        $this->logger->info("Fetching list page: {$url}");
        $html = $this->makeRequest($url);
        sleep($this->delaySeconds);
        return $html;
    }

    public function fetchDetailPage(string $url): string
    {
        $this->logger->info("Fetching detail page: {$url}");
        $html = $this->makeRequest($url);
        sleep($this->delaySeconds);
        return $html;
    }

    public function downloadFile(string $url, string $savePath): void
    {
        $this->logger->info("Downloading file: {$url}");
        $dir = dirname($savePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $ch = curl_init();
        $fp = fopen($savePath, 'w');
        // SSL certificate handling for macOS
        $certPaths = [
            '/opt/homebrew/etc/openssl@3/cert.pem',
            '/usr/local/etc/openssl@3/cert.pem',
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt'
        ];
        
        $curlOptions = [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ];
        
        $certPath = null;
        foreach ($certPaths as $path) {
            if (file_exists($path)) {
                $certPath = $path;
                break;
            }
        }
        
        if ($certPath) {
            $curlOptions[CURLOPT_CAINFO] = $certPath;
        }
        
        curl_setopt_array($ch, $curlOptions);

        $attempt = 0;
        while ($attempt < $this->maxRetries) {
            $success = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            if ($success && $httpCode >= 200 && $httpCode < 300) {
                curl_close($ch);
                fclose($fp);
                $this->logger->info("Downloaded: {$savePath}");
                sleep($this->delaySeconds);
                return;
            }

            $attempt++;
            if ($attempt < $this->maxRetries) {
                $this->logger->warning("Download failed (attempt {$attempt}/{$this->maxRetries})");
                sleep($this->retryDelaySeconds * $attempt);
            }
        }

        curl_close($ch);
        fclose($fp);
        if (file_exists($savePath)) {
            unlink($savePath);
        }
        throw new \RuntimeException("Failed to download {$url}: HTTP {$httpCode} - {$error}");
    }

    public function parseListPage(string $html): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($dom);

        $laws = [];
        
        // Try to find links with MasterID in href or data attributes
        // Common patterns: MasterID=, id=, or in query params
        $links = $xpath->query("//a[contains(@href, 'MasterID') or contains(@href, 'masterid')]");
        
        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            $title = trim($link->textContent);
            
            if (empty($href) || empty($title)) {
                continue;
            }

            // Extract MasterID from href
            if (preg_match('/MasterID[=:](\d+)/i', $href, $matches)) {
                $masterId = $matches[1];
                
                // Build full URL if relative
                if (strpos($href, 'http') !== 0) {
                    $baseUrl = 'https://www.nrsr.sk/web/';
                    if (strpos($href, '/') === 0) {
                        $href = 'https://www.nrsr.sk' . $href;
                    } else {
                        $href = $baseUrl . $href;
                    }
                }

                // Try to find date in nearby elements
                $date = null;
                $parent = $link->parentNode;
                if ($parent) {
                    $siblings = $xpath->query(".//text()[normalize-space()]", $parent);
                    foreach ($siblings as $sibling) {
                        $text = trim($sibling->nodeValue);
                        if (preg_match('/\d{1,2}\.\d{1,2}\.\d{4}/', $text, $dateMatch)) {
                            $date = $dateMatch[0];
                            break;
                        }
                    }
                }

                $laws[] = [
                    'master_id' => $masterId,
                    'title' => $title,
                    'url' => $href,
                    'approval_date' => $date
                ];
            }
        }

        $this->logger->info("Parsed " . count($laws) . " laws from list page");
        return $laws;
    }

    public function parseDetailPage(string $html, string $masterId): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($dom);

        $attachments = [];

        // Primary strategy: Look for "Všetky dokumenty v balíku ZIP" link
        // This is the main ZIP bundle containing all documents
        $allDocsLinks = $xpath->query("//a[contains(translate(text(), 'VŠETKY DOKUMENTY V BALÍKU ZIP', 'všetky dokumenty v balíku zip'), 'všetky dokumenty v balíku zip')]");
        
        foreach ($allDocsLinks as $link) {
            $href = $link->getAttribute('href');
            if (!empty($href)) {
                if (strpos($href, 'http') !== 0) {
                    $href = 'https://www.nrsr.sk' . (strpos($href, '/') === 0 ? '' : '/web/') . $href;
                }
                
                // Verify it's the DownloadSslpZip.aspx URL pattern
                if (stripos($href, 'DownloadSslpZip.aspx') !== false && stripos($href, 'MasterId=' . $masterId) !== false) {
                    $attachments[] = [
                        'url' => $href,
                        'filename' => 'vsetky_dokumenty_' . $masterId . '.zip',
                        'type' => 'zip',
                        'is_zip' => true
                    ];
                    $this->logger->info("Found 'Všetky dokumenty v balíku ZIP' link for MasterID {$masterId}");
                    break; // Only need one
                }
            }
        }

        // Fallback: Look for DownloadSslpZip.aspx URL pattern even if text doesn't match exactly
        if (empty($attachments)) {
            $zipLinks = $xpath->query("//a[contains(translate(@href, 'DOWNLOADSSLPZIP', 'downloadsslpzip'), 'downloadsslpzip.aspx')]");
            
            foreach ($zipLinks as $link) {
                $href = $link->getAttribute('href');
                if (!empty($href)) {
                    if (strpos($href, 'http') !== 0) {
                        $href = 'https://www.nrsr.sk' . (strpos($href, '/') === 0 ? '' : '/web/') . $href;
                    }
                    
                    // Check if it's the main bundle (section=zaklad) or contains MasterId
                    if (stripos($href, 'DownloadSslpZip.aspx') !== false && 
                        (stripos($href, 'section=zaklad') !== false || stripos($href, 'MasterId=' . $masterId) !== false)) {
                        $attachments[] = [
                            'url' => $href,
                            'filename' => 'vsetky_dokumenty_' . $masterId . '.zip',
                            'type' => 'zip',
                            'is_zip' => true
                        ];
                        $this->logger->info("Found DownloadSslpZip.aspx link for MasterID {$masterId}");
                        break; // Only need one
                    }
                }
            }
        }

        // Last resort: Look for individual DOCX and PDF files if no ZIP found
        if (empty($attachments)) {
            $docLinks = $xpath->query("//a[contains(@href, '.docx') or contains(@href, '.pdf') or contains(@href, '.doc')]");
            
            foreach ($docLinks as $link) {
                $href = $link->getAttribute('href');
                if (!empty($href)) {
                    if (strpos($href, 'http') !== 0) {
                        $href = 'https://www.nrsr.sk' . (strpos($href, '/') === 0 ? '' : '/web/') . $href;
                    }
                    
                    $path = parse_url($href, PHP_URL_PATH);
                    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    if (in_array($ext, ['docx', 'pdf', 'doc'])) {
                        $attachments[] = [
                            'url' => $href,
                            'filename' => basename($path) ?: "download.{$ext}",
                            'type' => $ext,
                            'is_zip' => false
                        ];
                    }
                }
            }
        }

        $this->logger->info("Found " . count($attachments) . " attachments for MasterID {$masterId}");
        return $attachments;
    }

    public function extractDeliveryDate(string $html): ?string
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($dom);

        // Look for "Dátum doručenia:" text
        $textNodes = $xpath->query("//text()[contains(., 'Dátum doručenia')]");
        
        foreach ($textNodes as $textNode) {
            $text = trim($textNode->nodeValue);
            
            // Try to find date pattern after "Dátum doručenia:"
            // Pattern: "Dátum doručenia: 19. 8. 2025" or "Dátum doručenia:19. 8. 2025"
            if (preg_match('/Dátum doručenia\s*:?\s*(\d{1,2}\.\s*\d{1,2}\.\s*\d{4})/', $text, $matches)) {
                // Normalize date format: remove extra spaces
                $date = preg_replace('/\s+/', ' ', $matches[1]);
                $this->logger->info("Extracted delivery date: {$date}");
                return $date;
            }
            
            // Also check parent/sibling nodes for date
            $parent = $textNode->parentNode;
            if ($parent) {
                $parentText = trim($parent->textContent);
                if (preg_match('/Dátum doručenia\s*:?\s*(\d{1,2}\.\s*\d{1,2}\.\s*\d{4})/', $parentText, $matches)) {
                    $date = preg_replace('/\s+/', ' ', $matches[1]);
                    $this->logger->info("Extracted delivery date from parent: {$date}");
                    return $date;
                }
            }
        }

        // Alternative: Look for any date near "doručenia" or "doručení"
        $allText = $dom->textContent;
        if (preg_match('/(?:Dátum\s+)?doručenia\s*:?\s*(\d{1,2}\.\s*\d{1,2}\.\s*\d{4})/i', $allText, $matches)) {
            $date = preg_replace('/\s+/', ' ', $matches[1]);
            $this->logger->info("Extracted delivery date (alternative method): {$date}");
            return $date;
        }

        $this->logger->warning("Could not extract delivery date from detail page");
        return null;
    }
}

