<?php

namespace App;

class OpenAIClient
{
    private string $apiKey;
    private string $model;
    private int $maxTokens;
    private Logger $logger;

    public function __construct(string $apiKey, string $model, int $maxTokens, Logger $logger)
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->maxTokens = $maxTokens;
        $this->logger = $logger;
    }

    /**
     * @param array<int,mixed> $options
     * @return array<int,mixed>
     */
    private function buildApiCurlOptions(array $options, bool $forceDirect = false): array
    {
        $defaults = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_FOLLOWLOCATION => true,
        ];

        if ($forceDirect) {
            $defaults[CURLOPT_PROXY] = '';
            $defaults[CURLOPT_NOPROXY] = '*';
            $defaults[CURLOPT_HTTPPROXYTUNNEL] = false;
        }

        return $options + $defaults;
    }

    /**
     * Try OpenAI requests both with default network settings and direct mode.
     *
     * @param array<int,mixed> $options
     * @return array{response:string,http_code:int,error:string}
     */
    private function executeApiRequest(string $url, array $options): array
    {
        $attempts = [
            'default' => false,
            'direct' => true,
        ];
        $last = ['response' => '', 'http_code' => 0, 'error' => ''];

        foreach ($attempts as $label => $forceDirect) {
            $ch = curl_init($url);
            curl_setopt_array($ch, $this->buildApiCurlOptions($options, $forceDirect));
            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = (string) curl_error($ch);
            curl_close($ch);

            $last = [
                'response' => is_string($response) ? $response : '',
                'http_code' => $httpCode,
                'error' => $error,
            ];

            if ($error === '' && $httpCode > 0) {
                if ($label === 'direct') {
                    $this->logger->info('OpenAI request succeeded in direct mode');
                }
                return $last;
            }

            $this->logger->warning("OpenAI request attempt {$label} failed", [
                'http_code' => $httpCode,
                'error' => $error,
                'response_preview' => is_string($response) ? mb_substr($response, 0, 200, 'UTF-8') : '',
            ]);
        }

        return $last;
    }

    public function generateSummary(string $text): array
    {
        $this->logger->info("Generating AI summary (text length: " . strlen($text) . " chars)");

        $prompt = $this->buildPrompt($text);
        
        $result = $this->executeApiRequest('https://api.openai.com/v1/chat/completions', [
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Si expertný právny analytik zameraný na kritické hodnotenie legislatívy. Tvoj predvolený postoj je skeptický audit: identifikuješ riziká, implementačné slabiny, nejasnosti, presuny nákladov na občanov a miesta, kde sa deklarované ciele nemusia naplniť. Pozitíva uvádzaj len ak sú jasne podložené textom zákona. Neopakuj politické tvrdenia bez dôkazu. Vždy odpovedáš v JSON formáte podľa presnej schémy.'
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt
                    ]
                ],
                'temperature' => 0.2,
                'max_tokens' => $this->maxTokens,
                'response_format' => ['type' => 'json_object']
            ]),
            CURLOPT_TIMEOUT => 60
        ]);
        $response = $result['response'];
        $httpCode = $result['http_code'];
        $error = $result['error'];

        if ($response === false || !empty($error)) {
            throw new \RuntimeException("OpenAI API request failed: {$error}");
        }

        if ($httpCode !== 200) {
            $this->logger->error("OpenAI API returned HTTP {$httpCode}: {$response}");
            throw new \RuntimeException("OpenAI API returned HTTP {$httpCode}");
        }

        $data = json_decode($response, true);
        if (!isset($data['choices'][0]['message']['content'])) {
            throw new \RuntimeException("Invalid OpenAI API response structure");
        }

        $content = $data['choices'][0]['message']['content'];
        $summary = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error("Failed to parse JSON response: " . json_last_error_msg());
            throw new \RuntimeException("Invalid JSON in AI response");
        }

        // Normalize to ensure arrays contain strings
        $summary = $this->normalizeSummary($summary);

        // Validate schema
        $this->validateSchema($summary);

        $this->logger->info("AI summary generated successfully");
        return $summary;
    }

    /**
     * Generate aggregated period summary from multiple law summaries.
     * @param array<int, array> $lawSummaries Each: human_title, summary_paragraph, affected_groups, positives, negatives
     * @param string $periodLabel e.g. "január 2026", "Q1 2026", "rok 2025"
     * @return array{summary_paragraph: string, changes: string, affected_groups: array, positives: array, negatives: array}
     */
    public function generatePeriodSummary(array $lawSummaries, string $periodLabel): array
    {
        $lawCount = count($lawSummaries);
        $this->logger->info("Generating period summary for {$periodLabel} ({$lawCount} laws)");

        $input = [];
        foreach ($lawSummaries as $i => $s) {
            $title = $s['human_title'] ?? $s['title'] ?? 'Zákon ' . ($i + 1);
            $summary = is_string($s['summary_paragraph'] ?? null) ? $s['summary_paragraph'] : '';
            $groups = is_array($s['affected_groups'] ?? null) ? $s['affected_groups'] : [];
            $pos = is_array($s['positives'] ?? null) ? $s['positives'] : [];
            $neg = is_array($s['negatives'] ?? null) ? $s['negatives'] : [];
            $input[] = [
                'human_title' => $title,
                'summary_paragraph' => $summary,
                'affected_groups' => $groups,
                'positives' => $pos,
                'negatives' => $neg,
            ];
        }

        $context = json_encode($input, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $compactContext = $this->buildCompactPeriodContext($input);
        $draftPrompt = $this->buildPeriodSummaryPrompt($context, $periodLabel);

        $draftResp = $this->requestJson('https://api.openai.com/v1/chat/completions', [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Si kritický analytik zákonov. Tvojou úlohou je hľadať slabiny návrhu, riziká implementácie, rozpor medzi deklarovaným cieľom a mechanizmom a možné negatívne dôsledky. Pozitíva uvádzaj iba ak sú priamo podložené.'
                ],
                ['role' => 'user', 'content' => $draftPrompt],
            ],
            'temperature' => 0.1,
            'max_tokens' => $this->maxTokens,
            'response_format' => ['type' => 'json_object'],
        ], 120);

        if ($draftResp['http_code'] !== 200) {
            $this->logger->error("OpenAI draft period summary HTTP {$draftResp['http_code']}: {$draftResp['response']}");
            throw new \RuntimeException("OpenAI API returned HTTP {$draftResp['http_code']}");
        }

        $draftData = json_decode($draftResp['response'], true);
        if (!is_array($draftData) || !isset($draftData['choices'][0]['message']['content'])) {
            throw new \RuntimeException("Invalid OpenAI API response structure");
        }
        $draft = $this->decodeJsonObjectFromText((string) $draftData['choices'][0]['message']['content']);
        if (!is_array($draft)) {
            throw new \RuntimeException("Invalid JSON in period summary draft");
        }
        $draft = $this->normalizePeriodSummary($draft);
        if ($this->isPeriodSummaryTooNarrow($draft, $lawCount)) {
            $draft = $this->expandPeriodSummaryBreadth($draft, $periodLabel, $compactContext, $lawCount);
        }

        // 2nd pass: web-based critical review. If unavailable, keep draft.
        $final = $draft;
        try {
            $reviewPrompt = $this->buildPeriodSummaryWebReviewPrompt($periodLabel, $compactContext, $draft);
            $review = $this->runWebReviewedPeriodSummary($reviewPrompt);
            $review['external_review_used'] = true;
            $final = $this->mergePeriodSummary($draft, $review);
            if ($this->isPeriodSummaryTooNarrow($final, $lawCount)) {
                $this->logger->warning("Merged period summary too narrow, falling back to broadened draft");
                $final = $draft;
                $final['external_review_used'] = false;
            }
        } catch (\Throwable $e) {
            $this->logger->warning("Period summary web review skipped: " . $e->getMessage());
            $final['external_review_used'] = false;
        }

        $this->logger->info("Period summary generated successfully");
        return $final;
    }

    private function buildPeriodSummaryPrompt(string $contextJson, string $periodLabel): string
    {
        return <<<PROMPT
Na základe nasledujúcich AI zhrnutí jednotlivých zákonov a listín zo Zbierky zákonov SR (Slov-Lex) a NR SR za obdobie „{$periodLabel}“ vytvor KRITICKÉ agregované zhrnutie, ktoré pokrýva CELÝ rozsah obdobia.

Štruktúra výstupu (JSON):
{
  "summary_paragraph": "Stručné zhrnutie jedným odstavcom (3-5 viet): čo sa zmenilo a kde sú hlavné slabé miesta alebo riziká.",
  "changes": "Konkrétne zmeny + prečo môžu v praxi zlyhať (vykonateľnosť, nejasnosti, administratívna záťaž, financovanie, kontrola).",
  "affected_groups": ["skupina 1 s vysvetlením", "skupina 2 s vysvetlením", ...],
  "positives": ["len preukázané pozitívum (ak chýba dôkaz, neuvádzaj)", ...],
  "negatives": ["konkrétne riziko/problém 1", "konkrétne riziko/problém 2", ...]
}

Pravidlá:
- Zhrň všetky zákony do jedného celku, neopakuj zbytočne.
- affected_groups: zlúč podobné skupiny, uveď kto je zasiahnutý.
- Predvolený tón je kritický audit, nie PR text.
- Najprv identifikuj negatíva a miesta zlyhania, až potom prípadné preukázané pozitíva.
- Ak niečo znie ako politická deklarácia bez mechanizmu, označ to za nepotvrdené tvrdenie.
- positives: max 0-2 body, a len ak sú preukázateľné priamo zo vstupu.
- negatives: aspoň 5 bodov, konkrétne a vecne.
- Zhrnutie NESMIE byť postavené na jednom paragrafe alebo jednom zákone; musí pokryť viac hlavných zmien za celé obdobie.
- Pri väčšom počte zákonov uprednostni široké pokrytie tém pred detailom jedného opatrenia.
- Píš v slovenčine, zrozumiteľne pre bežných občanov.
- Ak je málo zákonov, buď stručnejší. Ak je veľa, vyber najdôležitejšie.

Vstupné zhrnutia zákonov:
{$contextJson}
PROMPT;
    }

    /**
     * @param array<string,mixed> $draft
     */
    private function buildPeriodSummaryWebReviewPrompt(string $periodLabel, string $contextJson, array $draft): string
    {
        $draftJson = json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return <<<PROMPT
Máš pripravený interný draft kritického reportu o období "{$periodLabel}".

Úloha: vykonaj webový audit draftu na základe viacerých vyhľadávaní a zreviduj výstup.
Hľadaj hlavne:
1) mediálne a odborné výhrady,
2) implementačné problémy v praxi,
3) rozpory medzi deklarovaným cieľom zákona a reálnymi dopadmi,
4) upozornenia watchdog organizácií, analytikov, ekonomických komentárov,
5) protiargumenty a neistoty.

Dôležité pravidlá:
- Buď kritický, vecný a dôkazový.
- Neopakuj neoverené tvrdenia o "zvýšenej transparentnosti" alebo "zlepšení", pokiaľ na to nie je mechanizmus alebo dôkaz.
- Ak je tvrdenie nejasné, explicitne to označ.
- Výstup MUSÍ byť validný JSON a nič iné.

Požadovaný výstup:
{
  "summary_paragraph": "Kritické zhrnutie obdobia jedným odstavcom.",
  "changes": "Najdôležitejšie zmeny + riziká ich vykonania.",
  "affected_groups": ["skupina + dopad", "..."],
  "positives": ["len preukázané pozitíva", "..."],
  "negatives": ["hlavné problémy/riziká", "..."]
}

Interný draft:
{$draftJson}

Interné podklady (zákony):
{$contextJson}
PROMPT;
    }

    private function runWebReviewedPeriodSummary(string $prompt): array
    {
        $webResult = $this->answerFromPassagesWithWebSearch(
            [],
            $prompt . "\n\nVýstup daj VÝHRADNE ako validný JSON objekt podľa požadovanej schémy.",
            [],
            '',
            'high'
        );

        $rawAnswer = (string) ($webResult['answer'] ?? '');
        $decoded = $this->decodeJsonObjectFromText($rawAnswer);
        if (!is_array($decoded)) {
            $repairPrompt = <<<PROMPT
Preveď nasledujúci text do validného JSON objektu s kľúčmi:
summary_paragraph (string), changes (string), affected_groups (array string), positives (array string), negatives (array string).

Ak niektorý údaj chýba, doplň prázdny string alebo prázdne pole. Výstup musí byť LEN JSON objekt.

Text:
{$rawAnswer}
PROMPT;
            $repairResp = $this->requestJson('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'Si asistent na transformáciu textu do presného JSON formátu.'],
                    ['role' => 'user', 'content' => $repairPrompt],
                ],
                'temperature' => 0,
                'max_tokens' => min($this->maxTokens, 3000),
                'response_format' => ['type' => 'json_object'],
            ], 90);
            if ($repairResp['http_code'] === 200) {
                $repairData = json_decode($repairResp['response'], true);
                if (is_array($repairData) && isset($repairData['choices'][0]['message']['content'])) {
                    $decoded = $this->decodeJsonObjectFromText((string) $repairData['choices'][0]['message']['content']);
                }
            }
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON from web review');
        }
        $decoded = $this->normalizePeriodSummary($decoded);
        $decoded['external_citations'] = is_array($webResult['citations'] ?? null) ? $webResult['citations'] : [];
        return $decoded;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalizePeriodSummary(array $data): array
    {
        $data['summary_paragraph'] = trim((string) ($data['summary_paragraph'] ?? ''));
        $data['changes'] = trim((string) ($data['changes'] ?? $data['summary_paragraph'] ?? ''));
        $data['affected_groups'] = is_array($data['affected_groups'] ?? null) ? $data['affected_groups'] : [];
        $data['positives'] = is_array($data['positives'] ?? null) ? $data['positives'] : [];
        $data['negatives'] = is_array($data['negatives'] ?? null) ? $data['negatives'] : [];
        if (count($data['positives']) > 2) {
            $data['positives'] = array_slice($data['positives'], 0, 2);
        }
        return $data;
    }

    /**
     * @param list<array<string,mixed>> $input
     */
    private function buildCompactPeriodContext(array $input): string
    {
        $limited = array_slice($input, 0, 60);
        $compact = [];
        foreach ($limited as $item) {
            $compact[] = [
                'human_title' => (string) ($item['human_title'] ?? ''),
                'summary_paragraph' => mb_substr((string) ($item['summary_paragraph'] ?? ''), 0, 360, 'UTF-8'),
                'affected_groups' => array_slice(is_array($item['affected_groups'] ?? null) ? $item['affected_groups'] : [], 0, 4),
                'positives' => array_slice(is_array($item['positives'] ?? null) ? $item['positives'] : [], 0, 2),
                'negatives' => array_slice(is_array($item['negatives'] ?? null) ? $item['negatives'] : [], 0, 4),
            ];
        }
        return (string) json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function isPeriodSummaryTooNarrow(array $summary, int $lawCount): bool
    {
        $affected = is_array($summary['affected_groups'] ?? null) ? count($summary['affected_groups']) : 0;
        $negatives = is_array($summary['negatives'] ?? null) ? count($summary['negatives']) : 0;
        $summaryWords = str_word_count(strip_tags((string) ($summary['summary_paragraph'] ?? '')));
        $changesWords = str_word_count(strip_tags((string) ($summary['changes'] ?? '')));

        if ($lawCount >= 20) {
            return $affected < 3 || $negatives < 5 || $summaryWords < 80 || $changesWords < 70;
        }
        if ($lawCount >= 8) {
            return $affected < 2 || $negatives < 4 || $summaryWords < 55 || $changesWords < 50;
        }
        return $affected < 1 || $negatives < 2 || $summaryWords < 35 || $changesWords < 30;
    }

    /**
     * @param array<string,mixed> $draft
     * @return array<string,mixed>
     */
    private function expandPeriodSummaryBreadth(array $draft, string $periodLabel, string $compactContext, int $lawCount): array
    {
        $draftJson = (string) json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $minNeg = $lawCount >= 20 ? 6 : ($lawCount >= 8 ? 4 : 2);
        $minAffected = $lawCount >= 20 ? 4 : ($lawCount >= 8 ? 3 : 2);

        $prompt = <<<PROMPT
Rozšír tento príliš úzky draft tak, aby objektívne pokryl celé obdobie "{$periodLabel}".

Pravidlá:
- Zachovaj kritický, vecný tón.
- Nepíš iba o jednom zákone alebo jednom opatrení.
- Pokry viac hlavných tematických okruhov v období.
- Uveď aspoň {$minAffected} zasiahnuté skupiny a aspoň {$minNeg} konkrétnych negatív/rizík.
- Pozitíva ponechaj maximálne 0-2 a len preukázané.
- Výstup musí byť LEN validný JSON s rovnakými kľúčmi.

Aktuálny draft:
{$draftJson}

Podklady (skrátené):
{$compactContext}
PROMPT;

        $resp = $this->requestJson('https://api.openai.com/v1/chat/completions', [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => 'Si kritický analytik verejnej politiky. Tvoj cieľ je široké, objektívne a vecné pokrytie celého obdobia.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.1,
            'max_tokens' => min($this->maxTokens, 4500),
            'response_format' => ['type' => 'json_object'],
        ], 90);

        if ($resp['http_code'] !== 200) {
            return $draft;
        }

        $data = json_decode($resp['response'], true);
        if (!is_array($data) || !isset($data['choices'][0]['message']['content'])) {
            return $draft;
        }
        $decoded = $this->decodeJsonObjectFromText((string) $data['choices'][0]['message']['content']);
        if (!is_array($decoded)) {
            return $draft;
        }
        $expanded = $this->normalizePeriodSummary($decoded);
        return $this->isPeriodSummaryTooNarrow($expanded, $lawCount) ? $draft : $expanded;
    }

    /**
     * @param array<string,mixed> $draft
     * @param array<string,mixed> $review
     * @return array<string,mixed>
     */
    private function mergePeriodSummary(array $draft, array $review): array
    {
        $merged = $draft;
        foreach (['summary_paragraph', 'changes', 'affected_groups', 'positives', 'negatives'] as $key) {
            if (isset($review[$key])) {
                $merged[$key] = $review[$key];
            }
        }
        if (isset($review['external_citations'])) {
            $merged['external_citations'] = $review['external_citations'];
        }
        if (array_key_exists('external_review_used', $review)) {
            $merged['external_review_used'] = (bool) $review['external_review_used'];
        }
        return $this->normalizePeriodSummary($merged);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeJsonObjectFromText(string $text): ?array
    {
        $trimmed = trim($text);
        $direct = json_decode($trimmed, true);
        if (is_array($direct)) {
            return $direct;
        }

        $start = strpos($trimmed, '{');
        $end = strrpos($trimmed, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $candidate = substr($trimmed, $start, $end - $start + 1);
        $decoded = json_decode($candidate, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function answerQuestion(string $lawText, string $question): string
    {
        $this->logger->info("Generating AI answer (text length: " . strlen($lawText) . " chars)");

        $result = $this->executeApiRequest('https://api.openai.com/v1/chat/completions', [
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->model,
                'messages' => $this->buildQaMessages($lawText, $question, []),
                'temperature' => 0.2,
                'max_tokens' => $this->maxTokens
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 60
        ]);
        $response = $result['response'];
        $httpCode = $result['http_code'];
        $error = $result['error'];

        if ($response === false || !empty($error)) {
            throw new \RuntimeException("OpenAI API request failed: {$error}");
        }

        if ($httpCode !== 200) {
            $this->logger->error("OpenAI API returned HTTP {$httpCode}: {$response}");
            throw new \RuntimeException("OpenAI API returned HTTP {$httpCode}");
        }

        $data = json_decode($response, true);
        if (!isset($data['choices'][0]['message']['content'])) {
            throw new \RuntimeException("Invalid OpenAI API response structure");
        }

        $answer = trim($data['choices'][0]['message']['content']);
        $this->logger->info("AI answer generated successfully");
        return $answer;
    }

    public function answerQuestionWithHistory(string $lawText, string $question, array $history): string
    {
        $this->logger->info("Generating AI answer with history (text length: " . strlen($lawText) . " chars)");

        $result = $this->executeApiRequest('https://api.openai.com/v1/chat/completions', [
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->model,
                'messages' => $this->buildQaMessages($lawText, $question, $history),
                'temperature' => 0.2,
                'max_tokens' => $this->maxTokens
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 60
        ]);
        $response = $result['response'];
        $httpCode = $result['http_code'];
        $error = $result['error'];

        if ($response === false || !empty($error)) {
            throw new \RuntimeException("OpenAI API request failed: {$error}");
        }

        if ($httpCode !== 200) {
            $this->logger->error("OpenAI API returned HTTP {$httpCode}: {$response}");
            throw new \RuntimeException("OpenAI API returned HTTP {$httpCode}");
        }

        $data = json_decode($response, true);
        if (!isset($data['choices'][0]['message']['content'])) {
            throw new \RuntimeException("Invalid OpenAI API response structure");
        }

        $answer = trim($data['choices'][0]['message']['content']);
        $this->logger->info("AI answer generated successfully");
        return $answer;
    }

    /**
     * Librarian: answer from pre-selected labeled passages (multiple laws).
     * @param list<array{content: string, master_id: string, title: string, section_title?: ?string}> $labeledPassages
     */
    public function answerFromPassagesWithHistory(array $labeledPassages, string $question, array $history): string
    {
        $contextParts = [];
        foreach ($labeledPassages as $p) {
            $title = $p['title'] ?? '';
            $masterId = $p['master_id'] ?? '';
            $section = isset($p['section_title']) && $p['section_title'] !== null && $p['section_title'] !== '' ? ' (' . $p['section_title'] . ')' : '';
            $contextParts[] = "[Zákon: {$title} | {$masterId}{$section}]\n" . ($p['content'] ?? '');
        }
        $contextText = implode("\n\n---\n\n", $contextParts);

        $systemPrompt = 'Si expertný právny poradca pre slovenské zákony. Odpovedáš na základe poskytnutých úryvkov z viacerých zákonov.

PRAVIDLÁ:
1. Odpovedaj VÝLUČNE na základe poskytnutých úryvkov. Ak odpoveď nie je v úryvkoch, povedz to jasne.
2. Pri odpovedi uvádzaj zdroje: názov zákona alebo jeho číslo (napr. 200/2025 Z.z.) a prípadne § alebo Čl., ak je to v úryvku uvedené.
3. Aj keď sa v podkladoch nachádza viac tém alebo viac zákonov, musíš odpovedať len k presnej téme z otázky používateľa. Nesmieš svojvoľne prepnúť na inú oblasť práva.
4. Buď zrozumiteľný a praktický. Odpoveď musí byť vecná a podrobná – rozviň kľúčové body, uvádzaj konkrétne fakty, nekoneč sa jedinou všeobecnou vetou.
5. Na konci pripoj krátke upozornenie, že nejde o právne poradenstvo.';

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        $recentHistory = array_slice($history, -6);
        foreach ($recentHistory as $item) {
            if (!is_array($item)) {
                continue;
            }
            $role = $item['role'] ?? '';
            $content = $item['content'] ?? '';
            if (!in_array($role, ['user', 'assistant'], true) || !is_string($content) || trim($content) === '') {
                continue;
            }
            $messages[] = ['role' => $role, 'content' => trim($content)];
        }
        $messages[] = ['role' => 'user', 'content' => "Relevantné úryvky zákonov:\n\n{$contextText}\n\n---\n\nOtázka: {$question}"];

        $result = $this->executeApiRequest('https://api.openai.com/v1/chat/completions', [
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->model,
                'messages' => $messages,
                'temperature' => 0.2,
                'max_tokens' => $this->maxTokens
            ], JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 90
        ]);
        $response = $result['response'];
        $httpCode = $result['http_code'];
        $error = $result['error'];
        if ($response === false || !empty($error)) {
            throw new \RuntimeException("OpenAI API request failed: {$error}");
        }
        if ($httpCode !== 200) {
            $this->logger->error("OpenAI API returned HTTP {$httpCode}: {$response}");
            throw new \RuntimeException("OpenAI API returned HTTP {$httpCode}");
        }
        $data = json_decode($response, true);
        if (!isset($data['choices'][0]['message']['content'])) {
            throw new \RuntimeException("Invalid OpenAI API response structure");
        }
        return trim($data['choices'][0]['message']['content']);
    }

    /**
     * Librarian with strict web search mode.
     * Never falls back to passage-only mode to avoid "old version" behavior.
     *
     * @param list<array{content: string, master_id: string, title: string, section_title?: ?string}> $labeledPassages
     * @return array{answer: string, citations: list<array{url: string, title: string}>, used_web_search: bool}
     */
    public function answerFromPassagesWithWebSearch(
        array $labeledPassages,
        string $question,
        array $history,
        string $modelOverride = '',
        string $reasoningEffort = 'medium'
    ): array {
        $contextParts = [];
        foreach ($labeledPassages as $p) {
            $title = $p['title'] ?? '';
            $masterId = $p['master_id'] ?? '';
            $section = isset($p['section_title']) && $p['section_title'] !== null && $p['section_title'] !== '' ? ' (' . $p['section_title'] . ')' : '';
            $content = trim((string) ($p['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $contextParts[] = "[Interný zdroj: {$title} | {$masterId}{$section}]\n{$content}";
        }
        $contextText = implode("\n\n---\n\n", $contextParts);

        $systemPrompt = 'Si senior analytik pre monitoring zmien v slovenských zákonoch. Tvoj cieľ je dať presnú, konkrétnu a aktuálnu odpoveď.

PRAVIDLÁ:
1. Najprv vyhodnoť interný právny kontext, ktorý dostaneš. Neprehliadaj ho a neignoruj ho.
2. Ak interný kontext nestačí na presnú odpoveď, doplň ho webovým vyhľadávaním.
3. Uprednostni oficiálne a aktuálne zdroje: Slov-Lex, NR SR, ministerstvá, dôvodové správy, seriózne odborné zdroje.
4. Ak je používateľ nespokojný s predchádzajúcou odpoveďou alebo žiada detail, neopakuj všeobecné frázy. Oprav sa a uveď konkrétne fakty.
5. Jasne rozlišuj medzi tým, čo vyplýva z interných zákonných podkladov, a tým, čo dopĺňaš z webu.
6. Keď nemáš dosť istoty, povedz to otvorene. Nevymýšľaj si.
7. Aj keď sa v internom kontexte nachádza viac zákonov alebo tém, nesmieš zameniť používateľovu tému za inú. Web musíš hľadať len k presnej téme z otázky.
8. Odpoveď má byť profesionálna, vecná a praktická. Na konci pripoj upozornenie, že nejde o právne poradenstvo.';

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        $recentHistory = array_slice($history, -6);
        foreach ($recentHistory as $item) {
            if (!is_array($item)) {
                continue;
            }
            $role = $item['role'] ?? '';
            $content = $item['content'] ?? '';
            if (!in_array($role, ['user', 'assistant'], true) || !is_string($content) || trim($content) === '') {
                continue;
            }
            $messages[] = ['role' => $role, 'content' => trim($content)];
        }
        $userMessage = $question;
        if ($contextText !== '') {
            $userMessage .= "\n\nInterný právny kontext pre odpoveď:\n\n" . $contextText;
        }
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        $searchModel = $modelOverride ?: 'gpt-4o-search-preview';
        $chatModels = array_values(array_unique([$searchModel, 'gpt-4o-mini-search-preview']));

        foreach ($chatModels as $chatModel) {
            $body = [
                'model' => $chatModel,
                'messages' => $messages,
                'web_search_options' => (object) [],
                'max_tokens' => min($this->maxTokens * 2, 8000),
            ];

            $result = $this->requestJson('https://api.openai.com/v1/chat/completions', $body, 120);
            if ($result['http_code'] !== 200) {
                $this->logger->warning("Search API HTTP {$result['http_code']} for {$chatModel}. Response: " . substr($result['response'], 0, 300));
                continue;
            }

            $data = json_decode($result['response'], true);
            if (!is_array($data) || !isset($data['choices'][0]['message']['content'])) {
                $this->logger->warning("Search API invalid response for {$chatModel}");
                continue;
            }

            $answer = trim((string) $data['choices'][0]['message']['content']);
            $citations = $this->extractCitationsFromChatCompletions($data);
            $this->logger->info("Search API success via {$chatModel}, citations=" . count($citations));
            return ['answer' => $answer, 'citations' => $citations, 'used_web_search' => true];
        }

        // Last resort web path: Responses API with web_search_preview.
        $responsesBody = [
            'model' => 'gpt-4.1',
            'input' => $messages,
            'tools' => [['type' => 'web_search_preview']],
            'max_output_tokens' => min($this->maxTokens * 2, 8000),
        ];
        $resp = $this->requestJson('https://api.openai.com/v1/responses', $responsesBody, 120);
        if ($resp['http_code'] === 200) {
            $data = json_decode($resp['response'], true);
            $answer = $this->extractTextFromResponsesApi($data);
            if ($answer !== '') {
                $citations = $this->extractCitationsFromResponsesApi($data);
                $this->logger->info("Search API success via responses, citations=" . count($citations));
                return ['answer' => $answer, 'citations' => $citations, 'used_web_search' => true];
            }
        } else {
            $this->logger->warning("Responses API HTTP {$resp['http_code']}. Response: " . substr($resp['response'], 0, 300));
        }

        throw new \RuntimeException('Web search temporarily unavailable');
    }

    /**
     * @param array<string,mixed> $body
     * @return array{http_code:int,error:string,response:string}
     */
    private function requestJson(string $url, array $body, int $timeout = 120): array
    {
        $result = $this->executeApiRequest($url, [
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => $timeout
        ]);
        return [
            'http_code' => $result['http_code'],
            'error' => $result['error'],
            'response' => $result['response'],
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return list<array{url:string,title:string}>
     */
    private function extractCitationsFromChatCompletions(array $data): array
    {
        $citations = [];
        $msg = $data['choices'][0]['message'] ?? [];
        foreach (($msg['annotations'] ?? []) as $ann) {
            if (!is_array($ann)) {
                continue;
            }
            $uc = is_array($ann['url_citation'] ?? null) ? $ann['url_citation'] : $ann;
            $url = (string) ($uc['url'] ?? '');
            $title = (string) ($uc['title'] ?? $url);
            if ($url !== '') {
                $citations[] = ['url' => $url, 'title' => $title];
            }
        }
        return $this->dedupeCitations($citations);
    }

    /**
     * @param array<string,mixed> $data
     */
    private function extractTextFromResponsesApi(array $data): string
    {
        $parts = [];
        foreach (($data['output'] ?? []) as $outItem) {
            if (!is_array($outItem)) {
                continue;
            }
            foreach (($outItem['content'] ?? []) as $contentItem) {
                if (!is_array($contentItem)) {
                    continue;
                }
                if (($contentItem['type'] ?? '') === 'output_text' && isset($contentItem['text'])) {
                    $parts[] = trim((string) $contentItem['text']);
                }
            }
        }
        return trim(implode("\n\n", array_filter($parts)));
    }

    /**
     * @param array<string,mixed> $data
     * @return list<array{url:string,title:string}>
     */
    private function extractCitationsFromResponsesApi(array $data): array
    {
        $citations = [];
        foreach (($data['output'] ?? []) as $outItem) {
            if (!is_array($outItem)) {
                continue;
            }
            foreach (($outItem['content'] ?? []) as $contentItem) {
                if (!is_array($contentItem)) {
                    continue;
                }
                foreach (($contentItem['annotations'] ?? []) as $ann) {
                    if (!is_array($ann)) {
                        continue;
                    }
                    $url = (string) ($ann['url'] ?? ($ann['url_citation']['url'] ?? ''));
                    $title = (string) ($ann['title'] ?? ($ann['url_citation']['title'] ?? $url));
                    if ($url !== '') {
                        $citations[] = ['url' => $url, 'title' => $title];
                    }
                }
            }
        }
        return $this->dedupeCitations($citations);
    }

    /**
     * @param list<array{url:string,title:string}> $citations
     * @return list<array{url:string,title:string}>
     */
    private function dedupeCitations(array $citations): array
    {
        $seen = [];
        return array_values(array_filter($citations, function ($c) use (&$seen) {
            $url = $c['url'] ?? '';
            if ($url !== '' && !isset($seen[$url])) {
                $seen[$url] = true;
                return true;
            }
            return false;
        }));
    }

    private function buildPrompt(string $text): string
    {
        return "Analyzuj nasledujúci text zo slovenského zákona a vytvor podrobný, kvalitný JSON objekt s týmito presnými kľúčmi:

{
  \"human_title\": \"Krátky, ľudský názov – o čom je zákon jednou vetou (max. 15 slov). Zrozumiteľné aj laikovi. Napr. 'Zmeny vo výške dôchodkov od budúceho roka' alebo 'Nové pravidlá pre parkovanie v centrách miest'.\",
  \"tags\": [\"ekonomika\", \"financie\"],
  \"summary_paragraph\": \"Detailné, viacodsekové zhrnutie zákona (minimálne 3-5 viet, ideálne 150-300 slov). Vysvetli: čo zákon mení, prečo to môže byť dôležité, aké sú kľúčové body, ktoré by ľudia mali vedieť. Používaj konkrétne príklady a situácie, kde je to možné. Píš živým, zrozumiteľným jazykom, ale zachovávaj presnosť.\",
  \"affected_groups\": [\"konkrétna skupina 1 s vysvetlením ako ich to ovplyvní\", \"konkrétna skupina 2 s vysvetlením ako ich to ovplyvní\"],
  \"positives\": [\"konkrétne pozitívum 1 s vysvetlením prečo je to pozitívne\", \"konkrétne pozitívum 2 s vysvetlením\"],
  \"negatives\": [\"konkrétne negatívum 1 s vysvetlením akých dôsledkov sa to týka\", \"konkrétne negatívum 2 s vysvetlením\"],
  \"how_to_react\": [\"konkrétna, akčná rada 1 - čo presne má človek urobiť, kedy, ako\", \"konkrétna, akčná rada 2 - krok za krokom\", \"konkrétna, akčná rada 3 - praktický tip\"],
  \"disclaimer\": \"Toto nie je právne poradenstvo. Informácie sú len informatívneho charakteru. Pre právne poradenstvo sa obráťte na kvalifikovaného právnika.\"
}

DETALNÉ INŠTRUKCIE PRE KAŽDÚ SEKCIU:

**human_title:**
- Krátky, ľudský názov zákona v jednej vete (max. 15 slov)
- Popíš ľudskou a zrozumiteľnou rečou, o čom zákon stručne je – aby aj laik pochopil
- Nie technický názov, ale vysvetlenie: napr. „Zmeny vo výške dôchodkov od budúceho roka“, „Nové pravidlá pre parkovanie v centrách miest“, „Úprava daní pre živnostníkov“
- Musí byť konkrétny a informatívny

**tags:**
- Identifikuj 1-5 kľúčových oblastí, ku ktorým zákon patrí
- Používaj presné, krátke tagy (max. 2 slová, ideálne 1 slovo)
- Možné tagy: \"ekonomika\", \"financie\", \"podnikanie\", \"školstvo\", \"zdravotníctvo\", \"sociálna politika\", \"doprava\", \"bývanie\", \"práca\", \"dane\", \"polícia\", \"justícia\", \"životné prostredie\", \"poľnohospodárstvo\", \"kultúra\", \"šport\", \"turizmus\", \"energetika\", \"IT\", \"telekomunikácie\", \"obrana\", \"verejná správa\", \"miestna samospráva\", \"rodina\", \"mládež\", \"seniori\", \"šport\", \"vzdelávanie\"
- Vyber len relevantné tagy - ak zákon patrí do viacerých oblastí, použij ich všetky
- Tagy musia byť v slovenčine, malými písmenami, bez diakritiky alebo s diakritikou (konzistentne)

**summary_paragraph:**
- Napíš podrobné, viacodsekové zhrnutie (minimálne 150 slov, ideálne 200-300 slov)
- Začni kontextom: čo sa mení a prečo
- Vysvetli kľúčové zmeny konkrétne, nie abstraktne
- Uveď príklady situácií, kde je to relevantné
- Spomeň časové aspekty (kedy to nadobudne platnosť, aké sú prechodné obdobia)
- Ak sú dôležité čísla, sumy, limity - uveď ich
- Používaj živý, zrozumiteľný jazyk, ale buď presný

**affected_groups:**
- Identifikuj konkrétne skupiny ľudí (nie len \"občania\", ale napr. \"zamestnanci v malých firmách do 10 zamestnancov\", \"študenti vysokých škôl\", \"dôchodcovia nad 65 rokov\")
- Pre každú skupinu stručne vysvetli, ako ich to konkrétne ovplyvní (nie len \"sú ovplyvnení\", ale \"budú musieť...\", \"stratia možnosť...\", \"získajú právo...\")
- Minimálne 3-5 konkrétnych skupín, ak je to možné

**positives:**
- Identifikuj skutočné výhody a pozitíva, nie len formálne
- Pre každé pozitívum vysvetli, prečo je to výhoda a pre koho konkrétne
- Ak sú to len teoretické výhody, ktoré v praxi nefungujú, spomeň to
- Buď realistický - nie každý zákon má len pozitíva

**negatives:**
- Identifikuj skutočné problémy, riziká a nevýhody
- Pre každé negatívum vysvetli, aké konkrétne dôsledky to môže mať
- Spomeň, kto konkrétne môže byť negatívne ovplyvnený
- Buď konštruktívny, ale aj uprimný

**how_to_react:**
- Toto je NAJDÔLEŽITEJŠIA sekcia - musí obsahovať konkrétne, akčné rady
- Každá rada musí byť praktická a použiteľná (nie len \"prečítajte si zákon\")
- Uveď konkrétne kroky: čo presne má človek urobiť, kedy, kde, ako
- Ak sú lehoty, termíny - uveď ich
- Ak sú potrebné dokumenty, formuláre - spomeň ich
- Ak sú kontakty, inštitúcie - uveď ich
- Ak sú možnosti odvolania, námietok - vysvetli proces
- Minimálne 3-5 konkrétnych, akčných rád
- Príklady dobrých rád: \"Do 30 dní od nadobudnutia platnosti zákona pošlite žiadosť na [inštitúcia] na adresu [adresa] s prílohou [dokumenty]. Formulár nájdete na [URL].\"
- Príklady zlých rád (NEPOUŽÍVAŤ): \"Prečítajte si zákon\", \"Kontaktujte právnika\", \"Buďte opatrní\"

VŠEOBECNÉ PRAVIDLÁ:
- Používaj LEN informácie z poskytnutého textu. Ak niečo nie je v texte, napíš \"Informácie o tomto aspekte nie sú v poskytnutom texte dostupné.\"
- Buď zrozumiteľný pre bežných ľudí bez právnického vzdelania
- Vyhýbaj sa halucináciám. Ak si nie si istý, povedz to
- Používaj opatrnú reč tam, kde je to vhodné (\"môže ovplyvniť\", \"pravdepodobne\", \"podľa textu zákona\")
- Predvolený analytický režim je KRITICKÝ AUDIT: hľadaj slabiny, riziká implementácie, nejasnosti a body, kde sa deklarovaný cieľ nemusí naplniť.
- Neopakuj politické alebo marketingové tvrdenia ako fakty, ak text zákona neobsahuje konkrétny mechanizmus, kontrolu a vykonateľnosť.
- Pozitíva uvádzaj len vtedy, ak sú preukázateľne podložené konkrétnou časťou textu.
- Pre \"how_to_react\": LEN legálne, súladné, praktické návrhy. Žiadne nelegálne rady, daňové úniky alebo \"exploity\"
- Všetky texty musia byť v slovenčine
- Odpovedaj VÝLUČNE v JSON formáte bez akýchkoľvek dodatočných komentárov
- Kvalita je dôležitejšia ako kvantita - lepšie menej, ale kvalitných bodov, než veľa povrchných

Text zákona:
" . substr($text, 0, 30000); // Limit to avoid token limits
    }

    private function buildQaPrompt(string $text, string $question): string
    {
        $textSnippet = substr($text, 0, 40000);
        return "Odpovedz na otázku na základe textu zákona nižšie. Ak odpoveď nie je v texte, povedz to priamo. Odpoveď ukonči krátkym upozornením, že nejde o právne poradenstvo.\n\nOtázka:\n{$question}\n\nText zákona:\n{$textSnippet}";
    }

    private function buildQaMessages(string $text, string $question, array $history): array
    {
        // Retrieve relevant passages instead of sending everything
        $passages = $this->retrieveRelevantPassages($text, $question, $history);
        $contextText = implode("\n\n---\n\n", $passages);
        
        // Summarize old history, keep only last 3 exchanges
        $historySummary = $this->summarizeHistory($history);
        $recentHistory = array_slice($history, -3);
        
        $systemPrompt = 'Si expertný právny poradca pre slovenské zákony. Tvoja úloha je poskytnúť praktickú, zrozumiteľnú a pravdivú odpoveď.

PRAVIDLÁ:
1. Odpovedaj IBA na základe poskytnutého textu zákona. Ak informácia nie je v texte, jasne povedz "Táto informácia nie je v texte zákona uvedená."
2. Buď praktický - vysvetli čo to znamená pre bežného človeka, nie právnické formulky.
3. Štruktúra odpovede:
   - Priama odpoveď na otázku (1-2 vety)
   - Praktické vysvetlenie (čo to znamená v praxi, koho sa týka)
   - Ak relevantné: konkrétne kroky čo má človek urobiť
4. Nepoužívaj zbytočné technikálie ani právnický žargón.
5. Ak si nie si istý, povedz to otvorene.
6. Na konci vždy pripoj krátke upozornenie že nejde o právne poradenstvo.';

        $messages = [
            [
                'role' => 'system',
                'content' => $systemPrompt
            ]
        ];
        
        // Add history summary if exists
        if ($historySummary !== '') {
            $messages[] = [
                'role' => 'user',
                'content' => "Zhrnutie predchádzajúcej konverzácie:\n{$historySummary}"
            ];
            $messages[] = [
                'role' => 'assistant',
                'content' => 'Rozumiem kontextu predchádzajúcej konverzácie.'
            ];
        }
        
        // Add law context
        $messages[] = [
            'role' => 'user',
            'content' => "Relevantné časti textu zákona:\n\n{$contextText}"
        ];
        $messages[] = [
            'role' => 'assistant',
            'content' => 'Mám k dispozícii relevantné časti zákona. Ako vám môžem pomôcť?'
        ];

        // Add recent history
        foreach ($recentHistory as $item) {
            if (!is_array($item)) {
                continue;
            }
            $role = $item['role'] ?? '';
            $content = $item['content'] ?? '';
            if (!in_array($role, ['user', 'assistant'], true)) {
                continue;
            }
            if (!is_string($content) || trim($content) === '') {
                continue;
            }
            $messages[] = [
                'role' => $role,
                'content' => $content
            ];
        }

        // Add current question
        $messages[] = [
            'role' => 'user',
            'content' => $question
        ];

        return $messages;
    }

    private function retrieveRelevantPassages(string $lawText, string $question, array $history): array
        {
            $queryText = $question;
            foreach (array_slice($history, -2) as $item) {
                if (isset($item['content']) && is_string($item['content'])) {
                    $queryText .= ' ' . $item['content'];
                }
            }
            
            $stopWords = ['a', 'ale', 'ani', 'bez', 'do', 'je', 'jeho', 'jej', 'k', 'kde', 'keď', 'kto', 'ktorá', 'ktoré', 'ktorý', 'ku', 'ma', 'má', 'na', 'nad', 'ne', 'nie', 'no', 'o', 'od', 'po', 'pod', 'pre', 'pri', 'sa', 's', 'so', 'som', 'sú', 'ta', 'tak', 'tá', 'tam', 'te', 'ten', 'tento', 'tie', 'to', 'tu', 'ty', 'u', 'už', 'v', 'vo', 'vy', 'za', 'že', 'z', 'zo'];
            $words = preg_split('/\s+/', mb_strtolower($queryText, 'UTF-8'));
            $keywords = array_filter($words, function($w) use ($stopWords) {
                return mb_strlen($w, 'UTF-8') > 2 && !in_array($w, $stopWords);
            });
            
            if (empty($keywords)) {
                return [substr($lawText, 0, 40000)];
            }
            
            $chunkSize = 2000;
            $chunks = [];
            $textLength = mb_strlen($lawText, 'UTF-8');
            
            for ($i = 0; $i < $textLength; $i += $chunkSize) {
                $chunk = mb_substr($lawText, $i, $chunkSize, 'UTF-8');
                if (trim($chunk) !== '') {
                    $chunks[] = $chunk;
                }
            }
            
            if (empty($chunks)) {
                return [substr($lawText, 0, 40000)];
            }
            
            $scoredChunks = [];
            foreach ($chunks as $idx => $chunk) {
                $score = 0;
                $chunkLower = mb_strtolower($chunk, 'UTF-8');
                foreach ($keywords as $keyword) {
                    $score += substr_count($chunkLower, $keyword);
                }
                if ($score > 0) {
                    $scoredChunks[] = ['chunk' => $chunk, 'score' => $score, 'idx' => $idx];
                }
            }
            
            usort($scoredChunks, function($a, $b) {
                return $b['score'] - $a['score'];
            });
            
            $selectedChunks = [];
            $totalLength = 0;
            $maxLength = 35000;
            
            foreach ($scoredChunks as $item) {
                $chunkLength = mb_strlen($item['chunk'], 'UTF-8');
                if ($totalLength + $chunkLength <= $maxLength) {
                    $selectedChunks[] = $item['chunk'];
                    $totalLength += $chunkLength;
                }
                if (count($selectedChunks) >= 6) {
                    break;
                }
            }
            
            if (count($selectedChunks) < 2 && $textLength > 0) {
                $firstChunk = mb_substr($lawText, 0, min(5000, $textLength), 'UTF-8');
                array_unshift($selectedChunks, $firstChunk);
            }
            
            return empty($selectedChunks) ? [substr($lawText, 0, 40000)] : $selectedChunks;
        }
    
        private function summarizeHistory(array $history): string
        {
            if (count($history) <= 3) {
                return '';
            }
            
            $toSummarize = array_slice($history, 0, -3);
            if (empty($toSummarize)) {
                return '';
            }
            
            $summaryText = '';
            foreach ($toSummarize as $item) {
                if (isset($item['role']) && isset($item['content'])) {
                    $role = $item['role'] === 'user' ? 'Otázka' : 'Odpoveď';
                    $content = mb_substr($item['content'], 0, 150, 'UTF-8');
                    $summaryText .= "- {$role}: {$content}...\n";
                }
            }
            
            return mb_substr($summaryText, 0, 500, 'UTF-8');
        }
    

    private function validateSchema(array $data): void
    {
        $required = ['human_title', 'tags', 'summary_paragraph', 'affected_groups', 'positives', 'negatives', 'how_to_react', 'disclaimer'];
        
        foreach ($required as $key) {
            if (!isset($data[$key])) {
                throw new \RuntimeException("Missing required key in AI response: {$key}");
            }
        }

        if (!is_string($data['summary_paragraph']) || !is_string($data['disclaimer'])) {
            throw new \RuntimeException("summary_paragraph and disclaimer must be strings");
        }
        if (!isset($data['human_title']) || !is_string($data['human_title'])) {
            throw new \RuntimeException("human_title must be a non-empty string");
        }

        $arrayKeys = ['tags', 'affected_groups', 'positives', 'negatives', 'how_to_react'];
        foreach ($arrayKeys as $key) {
            if (!is_array($data[$key])) {
                throw new \RuntimeException("{$key} must be an array");
            }
        }
        
        // Validate tags
        if (empty($data['tags'])) {
            throw new \RuntimeException("tags array must not be empty");
        }
    }

    private function normalizeSummary(array $data): array
    {
        $data['human_title'] = trim((string) ($data['human_title'] ?? ''));
        $data['tags'] = $this->normalizeTags($data['tags'] ?? []);
        $data['affected_groups'] = $this->normalizeList($data['affected_groups'] ?? [], 'group', 'impact');
        $data['positives'] = $this->normalizeList($data['positives'] ?? [], 'positive', 'explanation');
        $data['negatives'] = $this->normalizeList($data['negatives'] ?? [], 'negative', 'explanation');
        $data['how_to_react'] = $this->normalizeList($data['how_to_react'] ?? [], 'reaction', 'text', 'advice', 'details');

        return $data;
    }

    private function normalizeTags(array $tags): array
    {
        $normalized = [];
        foreach ($tags as $tag) {
            if (is_string($tag)) {
                $clean = trim(mb_strtolower($tag, 'UTF-8'));
                if ($clean !== '') {
                    $normalized[] = $clean;
                }
            }
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeList(array $items, string ...$keys): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $text = trim($item);
                if ($text !== '') {
                    $normalized[] = $text;
                }
                continue;
            }

            if (is_array($item)) {
                $primary = '';
                $secondary = '';
                $primaryFound = false;
                
                // First pass: find primary key
                foreach ($keys as $key) {
                    if (!empty($item[$key]) && is_string($item[$key])) {
                        $primary = trim($item[$key]);
                        $primaryFound = true;
                        break;
                    }
                }
                
                // Second pass: find secondary key (check all remaining keys)
                if ($primaryFound) {
                    foreach ($keys as $key) {
                        if (!empty($item[$key]) && is_string($item[$key]) && trim($item[$key]) !== $primary) {
                            $secondary = trim($item[$key]);
                            break;
                        }
                    }
                    // Also check common secondary keys that might not be in the list
                    if ($secondary === '') {
                        $commonSecondaryKeys = ['details', 'explanation', 'impact', 'text'];
                        foreach ($commonSecondaryKeys as $key) {
                            if (!empty($item[$key]) && is_string($item[$key]) && trim($item[$key]) !== $primary) {
                                $secondary = trim($item[$key]);
                                break;
                            }
                        }
                    }
                }

                if ($primary !== '' && $secondary !== '') {
                    $normalized[] = $primary . ': ' . $secondary;
                } elseif ($primary !== '') {
                    $normalized[] = $primary;
                } else {
                    $normalized[] = json_encode($item, JSON_UNESCAPED_UNICODE);
                }
                continue;
            }

            if ($item !== null) {
                $normalized[] = (string)$item;
            }
        }

        return $normalized;
    }
    
    public static function getTagColor(string $tag): string
    {
        // Taxónomia farieb pre tagy
        $colorMap = [
            // Ekonomika a financie
            'ekonomika' => '#3498db',      // Modrá
            'financie' => '#2ecc71',       // Zelená
            'dane' => '#e74c3c',           // Červená
            'podnikanie' => '#f39c12',     // Oranžová
            
            // Školstvo a vzdelávanie
            'školstvo' => '#9b59b6',       // Fialová
            'vzdelávanie' => '#9b59b6',   // Fialová
            
            // Zdravotníctvo
            'zdravotníctvo' => '#e91e63',  // Ružová
            'zdravie' => '#e91e63',        // Ružová
            
            // Sociálna politika
            'sociálna politika' => '#00bcd4', // Cyan
            'sociálne' => '#00bcd4',       // Cyan
            'rodina' => '#ff9800',         // Tmavá oranžová
            'mládež' => '#4caf50',         // Svetlá zelená
            'seniori' => '#795548',        // Hnedá
            
            // Doprava a infraštruktúra
            'doprava' => '#607d8b',        // Modrošedá
            'bývanie' => '#8bc34a',        // Svetlá zelená
            
            // Práca a zamestnanie
            'práca' => '#ff5722',          // Tmavá oranžová
            'zamestnanie' => '#ff5722',    // Tmavá oranžová
            
            // Bezpečnosť a právo
            'polícia' => '#3f51b5',        // Indigo
            'justícia' => '#673ab7',       // Hluboká fialová
            'obrana' => '#212121',         // Tmavá šedá
            
            // Životné prostredie
            'životné prostredie' => '#4caf50', // Zelená
            'ekológia' => '#4caf50',      // Zelená
            
            // Poľnohospodárstvo
            'poľnohospodárstvo' => '#8bc34a', // Svetlá zelená
            'poľnohospodárstvo' => '#8bc34a', // Svetlá zelená
            
            // Kultúra a šport
            'kultúra' => '#e91e63',       // Ružová
            'šport' => '#ff9800',          // Oranžová
            'turizmus' => '#00acc1',       // Cyan
            
            // Energetika a IT
            'energetika' => '#ffc107',     // Žltá
            'IT' => '#2196f3',            // Svetlá modrá
            'telekomunikácie' => '#2196f3', // Svetlá modrá
            
            // Verejná správa
            'verejná správa' => '#795548', // Hnedá
            'miestna samospráva' => '#607d8b', // Modrošedá
        ];
        
        // Normalize tag (lowercase, remove accents for matching)
        $normalized = mb_strtolower($tag, 'UTF-8');
        
        // Try exact match first
        if (isset($colorMap[$normalized])) {
            return $colorMap[$normalized];
        }
        
        // Try partial match
        foreach ($colorMap as $key => $color) {
            if (strpos($normalized, $key) !== false || strpos($key, $normalized) !== false) {
                return $color;
            }
        }
        
        // Default color if no match (generate from hash)
        $hash = md5($normalized);
        $colors = ['#3498db', '#2ecc71', '#e74c3c', '#f39c12', '#9b59b6', '#e91e63', '#00bcd4', '#ff9800'];
        return $colors[hexdec(substr($hash, 0, 1)) % count($colors)];
    }
}


