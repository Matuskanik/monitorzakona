<?php

namespace App;

class ParliamentScraper
{
    private Logger $logger;
    private int $delaySeconds;
    private int $maxRetries;
    private int $retryDelaySeconds;

    public function __construct(Logger $logger, int $delaySeconds = 2, int $maxRetries = 3, int $retryDelaySeconds = 4)
    {
        $this->logger = $logger;
        $this->delaySeconds = $delaySeconds;
        $this->maxRetries = $maxRetries;
        $this->retryDelaySeconds = $retryDelaySeconds;
    }

    public function fetchPage(string $url): string
    {
        $this->logger->info("Fetching parliament page: {$url}");
        $html = $this->request($url);
        sleep($this->delaySeconds);
        return $html;
    }

    public function parseVotingList(string $html): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $x = new \DOMXPath($dom);
        $rows = $x->query('//tr');
        $votings = [];

        foreach ($rows as $row) {
            $link = $x->query('.//a[contains(@href, "sid=schodze/hlasovanie/") and contains(@href, "ID=")]', $row)->item(0);
            if (!$link) {
                continue;
            }
            $href = trim($link->getAttribute('href'));
            $id = $this->queryParam($href, 'ID');
            if (!$id) {
                continue;
            }
            $cells = $x->query('.//td', $row);
            if ($cells->length < 3) {
                continue;
            }

            $rowText = trim(preg_replace('/\s+/u', ' ', $row->textContent ?? ''));
            $dt = $this->normalizeDateTime($rowText);
            $votingNumberText = trim(preg_replace('/\s+/u', ' ', $link->textContent ?? ''));
            $votingNumber = is_numeric($votingNumberText) ? (int)$votingNumberText : null;

            $title = 'Hlasovanie NR SR';
            $titleCandidate = '';
            foreach ($cells as $cell) {
                $cellText = trim(preg_replace('/\s+/u', ' ', $cell->textContent ?? ''));
                if ($cellText === '' || is_numeric($cellText)) {
                    continue;
                }
                if (preg_match('/^\d{1,2}\.\d{1,2}\.\d{4}/', $cellText)) {
                    continue;
                }
                if (str_contains($cellText, 'Hlasovanie č.')) {
                    continue;
                }
                if (mb_strlen($cellText, 'UTF-8') > mb_strlen($titleCandidate, 'UTF-8')) {
                    $titleCandidate = $cellText;
                }
            }
            if ($titleCandidate !== '') {
                $title = $titleCandidate;
            }

            $votings[(string)$id] = [
                'nrsr_voting_id' => (string)$id,
                'session_number' => is_numeric(trim($cells->item(0)?->textContent ?? '')) ? (int)trim($cells->item(0)->textContent) : null,
                'voting_number' => $votingNumber,
                'title' => $title,
                'voting_date' => $dt['date'],
                'voting_time' => $dt['time'],
                'source_url' => 'https://www.nrsr.sk/web/Default.aspx?sid=schodze/hlasovanie/hlasklub&ID=' . urlencode((string)$id),
            ];
        }

        return array_values($votings);
    }

    public function parseVotingDetail(string $html): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $x = new \DOMXPath($dom);
        $text = preg_replace('/\s+/u', ' ', html_entity_decode($dom->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $title = $this->field($text, '/Názov hlasovania\s*:?\s*(.+?)\s*(?:Výsledok hlasovania|Návrh prešiel|Prítomní|$)/u') ?? 'Hlasovanie NR SR';
        $resultText = $this->field($text, '/Výsledok hlasovania\s*:?\s*(.+?)\s*(?:Návrh prešiel|Návrh neprešiel|Prítomní|$)/u');
        if ($resultText === null) {
            $resultText = str_contains(mb_strtolower($text, 'UTF-8'), 'návrh prešiel') ? 'Návrh prešiel' : 'Návrh neprešiel';
        }
        $lower = mb_strtolower($resultText, 'UTF-8');
        $result = str_contains($lower, 'neprešiel') ? 'neschválené' : (str_contains($lower, 'prešiel') ? 'schválené' : 'nezistené');

        $counts = [
            'present_count' => $this->num($text, '/Prítomní\s+(\d+)/u'),
            'votes_for' => $this->num($text, '/\[(?:Z|ZA)\]\s*Za hlasovalo\s+(\d+)/u'),
            'votes_against' => $this->num($text, '/\[(?:P|PROTI)\]\s*Proti hlasovalo\s+(\d+)/u'),
            'votes_abstain' => $this->num($text, '/\[(?:\?|ZD)\]\s*Zdržalo sa hlasovania\s+(\d+)/u'),
            'votes_absent' => $this->num($text, '/\[(?:0)\]\s*Neprítomní\s+(\d+)/u'),
            'votes_did_not_vote' => $this->num($text, '/\[(?:N)\]\s*Nehlasovalo\s+(\d+)/u'),
        ];

        $votes = [];
        $seen = [];
        $links = $x->query('//a[contains(@href, "PoslanecID=")]');
        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            $mpId = $this->queryParam($href, 'PoslanecID');
            $name = trim(preg_replace('/\s+/u', ' ', $link->textContent));
            if (!$mpId || $name === '' || isset($seen[$mpId])) {
                continue;
            }
            $seen[$mpId] = true;
            $lineText = trim(preg_replace('/\s+/u', ' ', $link->parentNode?->textContent ?? ''));
            $code = 'N';
            if (preg_match('/\[(Z|P|\?|N|0)\]/u', $lineText, $m)) {
                $code = $m[1];
            }
            $votes[] = [
                'mp_nrsr_id' => (string)$mpId,
                'full_name' => $name,
                'vote_code' => $code,
                'vote_label' => $this->voteLabel($code),
                'profile_url' => $this->absUrl($href),
                'club' => $this->nearestClub($link),
            ];
        }

        return ['title' => $title, 'result' => $result, 'result_text' => $resultText, 'counts' => $counts, 'votes' => $votes];
    }

    public function parseMpsList(string $html): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $x = new \DOMXPath($dom);
        $links = $x->query('//a[contains(@href, "sid=poslanci/poslanec") and contains(@href, "PoslanecID=")]');
        $mps = [];
        foreach ($links as $link) {
            $href = trim($link->getAttribute('href'));
            $id = $this->queryParam($href, 'PoslanecID');
            $name = trim(preg_replace('/\s+/u', ' ', $link->textContent));
            if (!$id || $name === '') {
                continue;
            }
            $mps[(string)$id] = ['nrsr_id' => (string)$id, 'full_name' => $name, 'profile_url' => $this->absUrl($href)];
        }
        return array_values($mps);
    }

    public function parseMpProfile(string $html): array
    {
        $dom = new \DOMDocument();
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        $x = new \DOMXPath($dom);
        $text = preg_replace('/\s+/u', ' ', html_entity_decode($dom->textContent ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        $photo = null;
        $img = $x->query('//img[contains(@src, "Foto") or contains(@src, "Photo") or contains(@src, "Poslanec")]')->item(0);
        if ($img instanceof \DOMElement) {
            $photo = $this->absUrl((string)$img->getAttribute('src'));
        }

        return [
            'first_name' => $this->field($text, '/Meno\s+(.+?)\s+Priezvisko/u'),
            'last_name' => $this->field($text, '/Priezvisko\s+(.+?)\s+Kandidoval\(a\) za/u'),
            'title' => $this->field($text, '/Titul\s+(.+?)\s+Priezvisko/u'),
            'party' => $this->field($text, '/Kandidoval\(a\) za\s+(.+?)\s+Naroden/u'),
            'club' => $this->field($text, '/Členstvo\s+Klub\s+(.+?)\s+(?:Dokumenty|Aktivity|Deň v parlamente|Rýchly prístup)/u'),
            'district' => $this->field($text, '/Kraj\s+(.+?)\s+(?:E-mail|WWW|Členstvo)/u'),
            'birth_date' => $this->field($text, '/Naroden[ýá]\([áa]\)\s+([0-9]{1,2}\.\s*[0-9]{1,2}\.\s*[0-9]{4})/u'),
            'email' => $this->field($text, '/E-mail\s+([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/iu'),
            'website' => $this->field($text, '/WWW\s+(https?:\/\/\S+|www\.\S+)/iu'),
            'photo_url' => $photo,
        ];
    }

    private function request(string $url): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $attempt = 0;
        while ($attempt < $this->maxRetries) {
            $res = curl_exec($ch);
            $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            if ($res !== false && $http >= 200 && $http < 300 && $err === '') {
                curl_close($ch);
                return (string)$res;
            }
            $attempt++;
            if ($attempt < $this->maxRetries) {
                sleep($this->retryDelaySeconds * $attempt);
            }
        }
        $err = curl_error($ch);
        curl_close($ch);
        throw new \RuntimeException("Failed to fetch {$url}: {$err}");
    }

    private function voteLabel(string $code): string
    {
        return match ($code) {
            'Z' => 'za',
            'P' => 'proti',
            '?' => 'zdržal sa',
            '0' => 'neprítomný',
            'N' => 'nehlasoval',
            default => 'neznáme',
        };
    }

    private function field(string $text, string $pattern): ?string
    {
        return preg_match($pattern, $text, $m) ? trim($m[1]) : null;
    }

    private function num(string $text, string $pattern): ?int
    {
        return preg_match($pattern, $text, $m) ? (int)$m[1] : null;
    }

    private function queryParam(string $url, string $name): ?string
    {
        $query = parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_QUERY);
        if (!$query) {
            return null;
        }
        parse_str($query, $parts);
        return isset($parts[$name]) ? (string)$parts[$name] : null;
    }

    private function absUrl(string $url): string
    {
        $u = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($u === '') {
            return 'https://www.nrsr.sk/web/';
        }
        if (str_starts_with($u, 'http://') || str_starts_with($u, 'https://')) {
            return $u;
        }
        if (str_starts_with($u, '/')) {
            return 'https://www.nrsr.sk' . $u;
        }
        return 'https://www.nrsr.sk/web/' . ltrim($u, '/');
    }

    private function normalizeDateTime(string $raw): array
    {
        if (preg_match('/(\d{1,2})\.(\d{1,2})\.(\d{4})\s*(\d{1,2}:\d{2})?/', preg_replace('/\s+/u', ' ', trim($raw)), $m)) {
            return [
                'date' => sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]),
                'time' => $m[4] ?? '00:00',
            ];
        }
        return ['date' => null, 'time' => null];
    }

    private function nearestClub(\DOMNode $node): ?string
    {
        $cur = $node;
        for ($i = 0; $i < 12; $i++) {
            $cur = $cur->previousSibling;
            if (!$cur) {
                break;
            }
            $txt = trim(preg_replace('/\s+/u', ' ', $cur->textContent ?? ''));
            if (preg_match('/Klub\s+(.+)/u', $txt, $m)) {
                return trim($m[1]);
            }
        }
        return null;
    }
}
