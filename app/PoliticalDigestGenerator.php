<?php

namespace App;

/**
 * Generates political digest (today, this week, this month) with web search.
 * One API call per period for reliable real content. Links to MPs from our database.
 */
class PoliticalDigestGenerator
{
    private string $apiKey;
    private string $model;
    private Logger $logger;
    private Database $db;

    public function __construct(string $apiKey, Logger $logger, Database $db, string $model = 'gpt-4o-search-preview')
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->logger = $logger;
        $this->db = $db;
    }

    /**
     * @return array{today: array, this_week: array, this_month: array}
     */
    public function generate(): array
    {
        $today = date('Y-m-d');
        $todaySk = date('j.n.Y');
        $mpNames = $this->getMpNamesForContext();

        $digest = [
            'today' => $this->generatePeriod('dnes', $todaySk, $mpNames),
            'this_week' => $this->generatePeriod('tento týždeň', $todaySk, $mpNames),
            'this_month' => $this->generatePeriod('tento mesiac', $todaySk, $mpNames),
        ];
        $digest = $this->linkMps($digest);

        return $digest;
    }

    private function generatePeriod(string $periodLabel, string $todaySk, string $mpNames): array
    {
        $prompt = "Čo sa stalo {$periodLabel} ({$todaySk}) v slovenskej politike? Vyhľadaj v SME.sk, Denník N, Aktuality. Uveď konkrétne udalosti a mená.";

        $body = [
            'model' => $this->model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'web_search_options' => (object) [],
            'max_tokens' => 1500,
        ];

        $content = $this->callApi($body);
        sleep(2);
        return $this->textToPeriod($content, $periodLabel);
    }

    private function textToPeriod(string $text, string $periodLabel): array
    {
        $text = trim($text);
        $lower = mb_strtolower($text, 'UTF-8');
        $isEmpty = str_contains($lower, 'neboli zaznamenané')
            || str_contains($lower, 'neboli nájdené')
            || str_contains($lower, 'po vyhľadaní neboli')
            || str_contains($lower, 'nemám konkrétne')
            || str_contains($lower, 'ospravedlňujem')
            || str_contains($lower, 'odporúčam navštíviť')
            || strlen($text) < 80;

        if ($isEmpty) {
            return $this->emptyPeriod();
        }

        $events = [];
        foreach (preg_split('/\n|•|[-*]\s+/', $text) as $line) {
            $line = trim($line, " \t\n\r-*•.");
            if (strlen($line) > 20 && !preg_match('/^(Čo|Dátum|Vyhľadaj|Ak |Uveď)/u', $line)) {
                $events[] = $line;
            }
        }
        $events = array_slice(array_unique($events), 0, 8);

        $names = [];
        if (preg_match_all('/(?:minister|poslanec|predseda|premier)\s+([A-ZÁÄČĎÉÍĹĽŇÓÔŔŘŠŤÚÝŽ][a-záäčďéíĹľňóôŔřšťúýž]+\s+[A-ZÁÄČĎÉÍĹĽŇÓÔŔŘŠŤÚÝŽ][a-záäčďéíĹľňóôŔřšťúýž]+)/u', $text, $m)) {
            $names = array_unique(array_slice($m[1], 0, 5));
        }

        return [
            'summary' => mb_substr($text, 0, 600),
            'events' => $events,
            'responsible_names' => $names,
            'impact' => '',
            'for_citizens' => 'neutral',
            'for_citizens_why' => '',
        ];
    }

    private function getMpNamesForContext(): string
    {
        $mps = $this->db->getAllParliamentMpsForMosaic();
        $names = array_map(fn($m) => $m['full_name'] ?? '', $mps);
        return implode(', ', array_slice($names, 0, 80));
    }

    private function normalizePeriod(array $raw): array
    {
        return [
            'summary' => trim((string)($raw['summary'] ?? '')),
            'events' => array_values(array_filter(array_map('trim', (array)($raw['events'] ?? [])))),
            'responsible_names' => array_values(array_filter(array_map('trim', (array)($raw['responsible_names'] ?? [])))),
            'impact' => trim((string)($raw['impact'] ?? '')),
            'for_citizens' => in_array($raw['for_citizens'] ?? '', ['good', 'bad', 'neutral']) ? $raw['for_citizens'] : 'neutral',
            'for_citizens_why' => trim((string)($raw['for_citizens_why'] ?? '')),
        ];
    }

    private function emptyPeriod(): array
    {
        return [
            'summary' => 'Po vyhľadaní neboli nájdené novšie správy.',
            'events' => [],
            'responsible_names' => [],
            'impact' => '',
            'for_citizens' => 'neutral',
            'for_citizens_why' => '',
        ];
    }

    private function linkMps(array $digest): array
    {
        foreach (['today', 'this_week', 'this_month'] as $period) {
            $names = $digest[$period]['responsible_names'] ?? [];
            $linked = [];
            foreach ($names as $name) {
                $mp = $this->db->findParliamentMpByName((string)$name);
                if ($mp) {
                    $linked[] = ['name' => $mp['full_name'], 'id' => (int)$mp['id']];
                } else {
                    $linked[] = ['name' => $name, 'id' => null];
                }
            }
            $digest[$period]['responsible_linked'] = $linked;
        }
        return $digest;
    }

    private function callApi(array $body): string
    {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 120,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($error)) {
            throw new \RuntimeException("OpenAI API failed: {$error}");
        }
        if ($httpCode !== 200) {
            throw new \RuntimeException("OpenAI API HTTP {$httpCode}");
        }

        $data = json_decode($response, true);
        return trim($data['choices'][0]['message']['content'] ?? '');
    }

    private function parseJson(string $content): ?array
    {
        $content = trim($content);
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $content, $m)) {
            $content = trim($m[1]);
        } elseif (preg_match('/(\{[\s\S]*\})/', $content, $m)) {
            $content = $m[1];
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

}
