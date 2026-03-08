<?php

namespace App;

/**
 * Generates AI profile for MPs using web search (media scraping).
 * Output: last_month, last_year, famous_quote, expertise_areas.
 */
class MpProfileGenerator
{
    private string $apiKey;
    private string $model;
    private Logger $logger;

    public function __construct(string $apiKey, Logger $logger, string $model = 'gpt-4o-mini-search-preview')
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->logger = $logger;
    }

    /**
     * @return array{last_month: string, last_year: string, famous_quote: string|null, expertise_areas: array<string>}
     */
    public function generate(array $mp): array
    {
        $name = $mp['full_name'] ?? 'Neznámy poslanec';
        $party = $mp['party'] ?? $mp['club'] ?? '';
        $club = $mp['club'] ?? '';

        $parts = explode(', ', $name, 2);
        $lastName = $parts[1] ?? $parts[0] ?? '';
        $searchTerms = $name . ' poslanec NR SR';
        $userPrompt = <<<PROMPT
POUŽI WEB SEARCH – vykonaj dôkladný hlbkový prieskum. Vyhľadaj VŠETKO čo sa dá nájsť online o poslancovi NR SR: {$name}. Strana: {$party}.

Vyhľadaj viackrát (rôzne výrazy): "{$name}", "{$lastName} poslanec", "{$name} NR SR", "{$name} hlasovanie", "{$name} vyjadrenie" – v SME.sk, Denník N, Aktuality.sk, Pravda.sk, TA3, TASR, parlamentnelisty.sk, nrsr.sk, vlada.sk.

Vráť JSON s týmito poliami (buď konkrétne nálezy, alebo explicitne uveď že nič nebolo nájdené):

1. "last_month" (string): Všetko čo robil/povedal za posledný mesiac – hlasovania, návrhy, vyjadrenia v médiách, vystúpenia, kritiky, podpisy pod zákony. 5–8 viet. Ak po dôkladnom vyhľadaní nič nenájdeš, napíš presne: "Po dôkladnom vyhľadaní v slovenských médiách neboli nájdené žiadne informácie za posledný mesiac."
2. "last_year" (string): Všetko čo robil/povedal za posledný rok – hlavné témy, predkladané zákony, verejné vystúpenia, konflikty, úspechy, členstvo vo výboroch, citácie. 8–12 viet. Ak po dôkladnom vyhľadaní nič nenájdeš, napíš presne: "Po dôkladnom vyhľadaní v slovenských médiách neboli nájdené žiadne zhrnutia za posledný rok."
3. "famous_quote" (string|null): Najznámejší alebo najdôležitejší výrok poslanca – presná citácia v úvodzovkách so zdrojom. Ak žiadny nenájdeš, vráť null.
4. "expertise_areas" (array of strings): Oblasti pôsobenia – zdravotníctvo, vnútro, školstvo, doprava, životné prostredie, sociálna politika, ekonómia, justícia, obrana, kultúra, šport, regionálny rozvoj. Max 8 položiek. Ak nič nenájdeš, vráť prázdne pole [].

Uveď odkazy na zdroje v markdown formáte [názov](url) pri relevantných tvrdeniach. Odpovedz výhradne platným JSON.
PROMPT;

        $body = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Si analytik slovenskej politiky. MUSÍŠ vykonať dôkladný web search – viacero vyhľadávacích dotazov – aby si našiel VŠETKO čo existuje o poslancovi v slovenských médiách. Nevynechaj žiadne zdroje. Ak po skutočne dôkladnom hľadaní nič nenájdeš, explicitne to uveď. Vždy vráť kompletný štruktúrovaný JSON. Odpovedáš len platným JSON.',
                ],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'web_search_options' => (object) [],
            'max_tokens' => 2500,
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 90,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || !empty($error)) {
            throw new \RuntimeException("OpenAI API failed: {$error}");
        }

        if ($httpCode !== 200) {
            $errBody = substr($response, 0, 500);
            $this->logger->warning("MpProfileGenerator HTTP {$httpCode}: {$errBody}");
            throw new \RuntimeException("OpenAI API returned HTTP {$httpCode}: {$errBody}");
        }

        $data = json_decode($response, true);
        $content = trim($data['choices'][0]['message']['content'] ?? '');
        $profile = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($profile)) {
            if (preg_match('/\{[\s\S]*\}/', $content, $m)) {
                $profile = json_decode($m[0], true);
            }
        }
        if (!is_array($profile)) {
            $this->logger->warning("MpProfileGenerator invalid JSON for {$name}");
            return $this->emptyProfile();
        }

        return $this->normalizeProfile($profile);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{last_month: string, last_year: string, famous_quote: string|null, expertise_areas: array<string>}
     */
    private function normalizeProfile(array $raw): array
    {
        $lastMonth = is_string($raw['last_month'] ?? null) ? trim($raw['last_month']) : '';
        $lastYear = is_string($raw['last_year'] ?? null) ? trim($raw['last_year']) : '';
        $famousQuote = isset($raw['famous_quote']) && $raw['famous_quote'] !== null
            ? trim((string) $raw['famous_quote'])
            : null;
        if ($famousQuote === '') {
            $famousQuote = null;
        }
        $expertise = $raw['expertise_areas'] ?? [];
        if (!is_array($expertise)) {
            $expertise = [];
        }
        $expertise = array_values(array_filter(array_map(function ($v) {
            return is_string($v) ? trim($v) : '';
        }, $expertise)));

        return [
            'last_month' => $lastMonth ?: 'Po dôkladnom vyhľadaní v slovenských médiách neboli nájdené žiadne informácie za posledný mesiac.',
            'last_year' => $lastYear ?: 'Po dôkladnom vyhľadaní v slovenských médiách neboli nájdené žiadne zhrnutia za posledný rok.',
            'famous_quote' => $famousQuote,
            'expertise_areas' => $expertise,
        ];
    }

    public static function isPlaceholderContent(?array $profile): bool
    {
        if (!is_array($profile)) {
            return true;
        }
        $lm = trim((string)($profile['last_month'] ?? ''));
        $ly = trim((string)($profile['last_year'] ?? ''));
        $hasRealMonth = $lm !== '' && !str_contains($lm, 'neboli nájdené') && !str_contains($lm, 'nie sú dostupné');
        $hasRealYear = $ly !== '' && !str_contains($ly, 'neboli nájdené') && !str_contains($ly, 'nie sú dostupné');
        return !$hasRealMonth && !$hasRealYear;
    }

    /**
     * @return array{last_month: string, last_year: string, famous_quote: string|null, expertise_areas: array<string>}
     */
    private function emptyProfile(): array
    {
        return [
            'last_month' => 'Po dôkladnom vyhľadaní v slovenských médiách neboli nájdené žiadne informácie za posledný mesiac.',
            'last_year' => 'Po dôkladnom vyhľadaní v slovenských médiách neboli nájdené žiadne zhrnutia za posledný rok.',
            'famous_quote' => null,
            'expertise_areas' => [],
        ];
    }
}
