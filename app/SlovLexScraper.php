<?php

namespace App;

/**
 * Scraper for static.slov-lex.sk – Zbierka zákonov SR.
 * Fetches list of years, list of acts per year, and HTML content (vyhlásené znenie).
 */
class SlovLexScraper
{
    private const BASE_URL = 'https://static.slov-lex.sk';
    private const ZZ_PATH = '/static/SK/ZZ';

    private Logger $logger;
    private int $delaySeconds;
    private int $maxRetries;
    private int $retryDelaySeconds;

    public function __construct(Logger $logger, int $delaySeconds = 2, int $maxRetries = 3, int $retryDelaySeconds = 5)
    {
        $this->logger = $logger;
        $this->delaySeconds = $delaySeconds;
        $this->maxRetries = $maxRetries;
        $this->retryDelaySeconds = $retryDelaySeconds;
    }

    private function makeRequest(string $url): string
    {
        $ch = curl_init();
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
        $opts = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Sentinel/1.0; +https://github.com/sentinel)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($certPath) {
            $opts[CURLOPT_CAINFO] = $certPath;
        }
        curl_setopt_array($ch, $opts);

        $attempt = 0;
        while ($attempt < $this->maxRetries) {
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            if ($response === false || !empty($error)) {
                $attempt++;
                $this->logger->warning("SlovLex request failed (attempt {$attempt}/{$this->maxRetries}): {$error}");
                if ($attempt < $this->maxRetries) {
                    sleep($this->retryDelaySeconds * $attempt);
                } else {
                    curl_close($ch);
                    throw new \RuntimeException("Failed to fetch {$url}: {$error}");
                }
                continue;
            }
            if ($httpCode >= 200 && $httpCode < 300) {
                curl_close($ch);
                return $response;
            }
            $attempt++;
            $this->logger->warning("SlovLex HTTP {$httpCode} (attempt {$attempt}/{$this->maxRetries})");
            if ($attempt < $this->maxRetries) {
                sleep($this->retryDelaySeconds * $attempt);
            }
        }
        curl_close($ch);
        throw new \RuntimeException("Failed to fetch {$url}: HTTP {$httpCode}");
    }

    private function fetchWithDelay(string $url): string
    {
        $html = $this->makeRequest($url);
        sleep($this->delaySeconds);
        return $html;
    }

    /**
     * Parse ZZ index page and return list of year paths (e.g. ['2025', '2024', ...]).
     * @return array<int, string> year numbers
     */
    public function fetchYears(): array
    {
        $url = self::BASE_URL . self::ZZ_PATH . '/';
        $this->logger->info("SlovLex: fetching years from {$url}");
        $html = $this->fetchWithDelay($url);
        $years = $this->parseYearsPage($html);
        $this->logger->info("SlovLex: found " . count($years) . " years");
        return $years;
    }

    /**
     * @return array<int, string> year numbers (e.g. ['2025', '2024'])
     */
    public function parseYearsPage(string $html): array
    {
        $years = [];
        // Links like href="2025/" or href="/static/SK/ZZ/2025/"
        if (preg_match_all('#(?:href=["\'])(?:/static/SK/ZZ/)?(\d{4})/?["\']#', $html, $m)) {
            $years = array_unique(array_filter($m[1], function ($y) {
                $y = (int) $y;
                return $y >= 1945 && $y <= 2030;
            }));
        }
        rsort($years, SORT_NUMERIC);
        return array_values($years);
    }

    /**
     * Fetch one year's index and return list of acts: [ ['year' => 2025, 'number' => 200, 'title' => '...'], ... ]
     * @param string $year e.g. 2025
     * @return list<array{year: string, number: string, title: string}>
     */
    public function fetchYearIndex(string $year): array
    {
        $url = self::BASE_URL . self::ZZ_PATH . '/' . $year . '/';
        $this->logger->info("SlovLex: fetching year index {$year}");
        $html = $this->fetchWithDelay($url);
        return $this->parseYearIndexPage($html, $year);
    }

    /**
     * Parse year page: table with "Číslo predpisu" and "Názov predpisu". Links are like (200/) or href="200/"
     * @return list<array{year: string, number: string, title: string}>
     */
    public function parseYearIndexPage(string $html, string $year): array
    {
        $list = [];
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($dom);

        // Table rows: | Číslo predpisu | Názov predpisu |. Link href like "200/" or "/static/SK/ZZ/2025/200/"
        $rows = $xpath->query("//table//tr");
        foreach ($rows as $row) {
            $cells = $xpath->query('.//td', $row);
            if ($cells->length < 2) {
                continue;
            }
            $firstCell = trim($cells->item(0)->textContent ?? '');
            // First column: "200/2025 Z.z." or "1/2025Z.z."
            if (!preg_match('#^(\d+)/\d{4}\s*Z\.?\s*z\.?#ui', $firstCell, $numMatch)) {
                continue;
            }
            $number = $numMatch[1];
            $link = $xpath->query('.//a[contains(@href, "' . $number . '") or contains(@href, \'/' . $number . '/\')]', $row)->item(0)
                ?? $xpath->query('.//a', $row)->item(0);
            $title = $link ? trim($link->textContent ?? '') : trim($cells->item(1)->textContent ?? '');
            if (mb_strlen($title) < 10) {
                continue;
            }
            $list[] = [
                'year' => $year,
                'number' => $number,
                'title' => $title,
            ];
        }
        if (empty($list)) {
            // Fallback: any link ending with /{number}/
            $links = $xpath->query("//a[contains(@href, '/')]");
            foreach ($links as $link) {
                $href = $link->getAttribute('href');
                $title = trim($link->textContent ?? '');
                if (preg_match('#/(\d+)/?$#', $href, $m) && mb_strlen($title) > 10 && mb_strlen($title) < 2000) {
                    $list[] = ['year' => $year, 'number' => $m[1], 'title' => $title];
                }
            }
            $byNum = [];
            foreach ($list as $item) {
                $key = $item['number'];
                if (!isset($byNum[$key]) || mb_strlen($item['title']) > mb_strlen($byNum[$key]['title'] ?? '')) {
                    $byNum[$key] = $item;
                }
            }
            $list = array_values($byNum);
        }
        usort($list, function ($a, $b) {
            return (int) $a['number'] <=> (int) $b['number'];
        });
        $this->logger->info("SlovLex: parsed " . count($list) . " acts for year {$year}");
        return $list;
    }

    /**
     * Fetch vyhlásené znenie HTML for one act.
     */
    public function fetchLawHtml(string $year, string $number): string
    {
        $url = self::BASE_URL . self::ZZ_PATH . '/' . $year . '/' . $number . '/vyhlasene_znenie.html';
        $this->logger->info("SlovLex: fetching law {$year}/{$number}");
        return $this->fetchWithDelay($url);
    }

    /**
     * Extract plain text and metadata from vyhlasene_znenie.html.
     * @return array{text: string, title: string|null, type: string|null, approval_date: string|null, publication_date: string|null, author: string|null, tags: array<int, string>}
     */
    public function extractTextAndMetadata(string $html): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $xpath = new \DOMXPath($dom);

        $result = [
            'text' => '',
            'title' => null,
            'type' => null,
            'approval_date' => null,
            'publication_date' => null,
            'author' => null,
            'tags' => [],
        ];

        // Main content: often in <main> or div with id. Skip nav, tables for "História", "Informácie o predpise".
        // Try to get the main heading and body. On slov-lex the structure has h1 with number, then content.
        $main = $xpath->query("//*[local-name()='main']")->item(0)
            ?? $xpath->query("//*[@id='main-content']")->item(0)
            ?? $xpath->query("//body")->item(0);
        if (!$main) {
            $result['text'] = $this->stripHtmlToText($html);
            return $result;
        }

        // Build text from the main content. We intentionally skip meta tables
        // like "Informácie o predpise" and "História", ale zachováme celý
        // zvyšok dokumentu (plné znenie zákona).
        $textParts = [];
        $this->walkText($main, $xpath, $textParts, $result);
        $result['text'] = trim(implode("\n\n", $textParts));

        // If we didn't find metadata in walk, try table "Informácie o predpise"
        $tables = $xpath->query("//table");
        foreach ($tables as $table) {
            $tableText = $table->textContent ?? '';
            if (mb_strpos($tableText, 'Názov') !== false && mb_strpos($tableText, 'Číslo predpisu') !== false) {
                $this->parseInfoTable($table, $xpath, $result);
                break;
            }
        }

        // Právna oblasť: often in list items or comma-separated
        if (empty($result['tags'])) {
            $allText = $main->textContent ?? '';
            if (preg_match('/Právna oblasť|Informácie o predpise/su', $allText)) {
                $listItems = $xpath->query("//li[contains(., '–') or contains(., '-')]");
                foreach ($listItems as $li) {
                    $t = trim($li->textContent ?? '');
                    if ($t && mb_strlen($t) < 200 && !preg_match('/^\d+\./', $t)) {
                        $result['tags'][] = $t;
                    }
                }
            }
        }

        // Ak je text podozrivo krátky alebo neobsahuje žiadne štruktúrne značky
        // (§, Článok, ČASŤ, Hlava), fallback na jednoduché stripHtmlToText,
        // aby sme radšej mali celý text (aj s prípadným "šumom"), než iba úvod.
        $needsFallback = ($result['text'] === '');
        if (!$needsFallback) {
            $length = mb_strlen($result['text'], 'UTF-8');
            $hasStructure = (bool)preg_match(
                '/(§\s*\d+|Článok\s+\d+|PRVÁ\s+ČASŤ|DRUHÁ\s+ČASŤ|HLAVA\s+[IVXLCDM]+)/u',
                $result['text']
            );
            if ($length < 1000 && !$hasStructure) {
                $needsFallback = true;
            }
        }
        if ($needsFallback) {
            $result['text'] = $this->stripHtmlToText($html);
        }

        $result['text'] = preg_replace('/\n{3,}/', "\n\n", $result['text']);
        return $result;
    }

    private function walkText(\DOMNode $node, \DOMXPath $xpath, array &$textParts, array &$result): void
    {
        $nodeText = trim($node->textContent ?? '');
        $name = $node->nodeName ?? '';

        if ($name === 'table') {
            $tableText = $node->textContent ?? '';
            // Meta tabuľky – "Informácie o predpise", "Právna oblasť", základné údaje.
            // Použijeme ich len na metadáta, ale nevkladáme ich do textu zákona.
            if (mb_strpos($tableText, 'Informácie o predpise') !== false
                || mb_strpos($tableText, 'Právna oblasť') !== false
                || (mb_strpos($tableText, 'Názov') !== false && mb_strpos($tableText, 'Číslo predpisu') !== false)
            ) {
                $this->parseInfoTable($node, $xpath, $result);
                return;
            }
            // História účinnosti – úplne preskočíme.
            if (mb_strpos($tableText, 'História') !== false && mb_strpos($tableText, 'Dátum účinnosti') !== false) {
                return;
            }
        }

        if ($name === 'h1' && $nodeText) {
            if (!$result['title'] && preg_match('/^\d+\/\d+\s*Z\.\s*z\./u', $nodeText)) {
                $result['title'] = $nodeText;
            }
        }

        if ($name === '#text') {
            if ($nodeText !== '') {
                $textParts[] = $nodeText;
            }
            return;
        }

        if (\in_array($name, ['script', 'style', 'nav', 'header', 'footer'], true)) {
            return;
        }

        $childCount = 0;
        foreach ($node->childNodes as $child) {
            $this->walkText($child, $xpath, $textParts, $result);
            $childCount++;
        }
        if ($childCount === 0 && $nodeText !== '') {
            $textParts[] = $nodeText;
        }
    }

    private function parseInfoTable(\DOMNode $table, \DOMXPath $xpath, array &$result): void
    {
        $rows = $xpath->query('.//tr', $table);
        foreach ($rows as $row) {
            $cells = $xpath->query('.//td', $row);
            if ($cells->length >= 2) {
                $label = trim($cells->item(0)->textContent ?? '');
                $value = trim($cells->item(1)->textContent ?? '');
                if ($label === 'Názov:' || $label === 'Názov') {
                    $result['title'] = $value ?: $result['title'];
                } elseif ($label === 'Typ:' || $label === 'Typ') {
                    $result['type'] = $value ?: null;
                } elseif ($label === 'Dátum schválenia:' || $label === 'Dátum schválenia') {
                    $result['approval_date'] = $value ?: null;
                } elseif ($label === 'Dátum vyhlásenia:' || $label === 'Dátum vyhlásenia') {
                    $result['publication_date'] = $value ?: null;
                } elseif ($label === 'Autor:' || $label === 'Autor') {
                    $result['author'] = $value ?: null;
                } elseif ($label === 'Právna oblasť:' || $label === 'Právna oblasť') {
                    if ($value) {
                        $result['tags'] = array_filter(array_map('trim', preg_split('/[,;]|\s+–\s+/', $value)));
                    }
                }
            }
        }
        // Právna oblasť can be in following list
        $next = $table->nextSibling;
        while ($next) {
            if ($next->nodeType === XML_ELEMENT_NODE) {
                $listItems = $xpath->query('.//li', $next);
                foreach ($listItems as $li) {
                    $t = trim($li->textContent ?? '');
                    if ($t && mb_strlen($t) < 200) {
                        $result['tags'][] = $t;
                    }
                }
                if ($listItems->length > 0) {
                    break;
                }
            }
            $next = $next->nextSibling;
        }
        $result['tags'] = array_values(array_unique($result['tags']));
    }

    private function stripHtmlToText(string $html): string
    {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/si', '', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $html);
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /**
     * Build master_id for Slov-Lex act: slovlex-ZZ-{YEAR}-{NUMBER}
     */
    public static function masterId(string $year, string $number): string
    {
        return 'slovlex-ZZ-' . $year . '-' . $number;
    }

    /**
     * Build source URL for an act.
     */
    public static function sourceUrl(string $year, string $number): string
    {
        return self::BASE_URL . self::ZZ_PATH . '/' . $year . '/' . $number . '/';
    }
}
