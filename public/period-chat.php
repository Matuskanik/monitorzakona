<?php

ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');

set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Interná chyba: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ], JSON_UNESCAPED_UNICODE);
    exit;
});
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require_once __DIR__ . '/../vendor/autoload.php';
\App\Maintenance::check();

use App\Config;
use App\Database;
use App\Auth;
use App\OpenAIClient;
use App\Logger;

/**
 * @param list<array<string,mixed>> $laws
 * @param string[] $keywords
 * @param array|null $periodSummary Optional summary from period_summaries (summary_paragraph, changes, affected_groups, positives, negatives)
 * @param bool $includePeriodSummary Include cached whole-period summary only for broad overview questions
 * @param bool $allowBroadFallback Allow generic fallbacks across the whole period
 * @return list<array{content:string,master_id:string,title:string,section_title:?string}>
 */
function buildPeriodLabeledPassages(
    Database $db,
    array $laws,
    array $keywords,
    ?array $periodSummary = null,
    bool $includePeriodSummary = false,
    bool $allowBroadFallback = true
): array
{
    $maxContextChars = (int) Config::get('GLOBAL_CHAT_CONTEXT_CHARS', '50000');
    $maxContextChars = max(20000, min($maxContextChars, 60000));

    $periodMasterSet = [];
    foreach ($laws as $law) {
        $masterId = (string) ($law['master_id'] ?? '');
        if ($masterId !== '') {
            $periodMasterSet[$masterId] = true;
        }
    }

    $labeledPassages = [];
    $totalChars = 0;
    $seenChunkIds = [];

    if ($includePeriodSummary && $periodSummary !== null) {
        $parts = [];
        $s = (string) ($periodSummary['summary_paragraph'] ?? '');
        if ($s !== '') {
            $parts[] = "Stručné zhrnutie: " . $s;
        }
        $c = (string) ($periodSummary['changes'] ?? '');
        if ($c !== '') {
            $parts[] = "Aktuálne zmeny: " . $c;
        }
        $ag = $periodSummary['affected_groups'] ?? [];
        if (is_array($ag) && !empty($ag)) {
            $parts[] = "Zasiahnuté skupiny: " . implode('; ', array_map(static function ($x) {
                return is_string($x) ? $x : ($x['group'] ?? $x['impact'] ?? json_encode($x));
            }, $ag));
        }
        $pos = $periodSummary['positives'] ?? [];
        if (is_array($pos) && !empty($pos)) {
            $parts[] = "Pozitívne: " . implode('; ', array_map(static function ($x) {
                return is_string($x) ? $x : ($x['explanation'] ?? $x['positive'] ?? json_encode($x));
            }, $pos));
        }
        $neg = $periodSummary['negatives'] ?? [];
        if (is_array($neg) && !empty($neg)) {
            $parts[] = "Negatívne: " . implode('; ', array_map(static function ($x) {
                return is_string($x) ? $x : ($x['explanation'] ?? $x['negative'] ?? json_encode($x));
            }, $neg));
        }
        if (!empty($parts)) {
            $summaryContent = implode("\n\n", $parts);
            $labeledPassages[] = [
                'content' => $summaryContent,
                'master_id' => '_period_summary',
                'title' => 'Zhrnutie obdobia (agregované AI zhrnutie zákonov)',
                'section_title' => null,
            ];
            $totalChars += mb_strlen($summaryContent, 'UTF-8');
        }
    }

    if (!empty($keywords)) {
        $chunks = $db->getChunksMatchingKeywords($keywords, null, 500);
        foreach ($chunks as $c) {
            $masterId = (string) ($c['master_id'] ?? '');
            if (!isset($periodMasterSet[$masterId])) {
                continue;
            }
            $id = ($c['law_id'] ?? '') . '_' . ($c['chunk_index'] ?? '');
            if (isset($seenChunkIds[$id])) {
                continue;
            }
            $content = (string) ($c['content'] ?? '');
            if ($content === '') {
                continue;
            }
            $len = mb_strlen($content, 'UTF-8');
            if ($totalChars + $len > $maxContextChars) {
                break;
            }
            $seenChunkIds[$id] = true;
            $labeledPassages[] = [
                'content' => $content,
                'master_id' => $masterId,
                'title' => (string) ($c['title'] ?? $masterId),
                'section_title' => isset($c['section_title']) ? (string) $c['section_title'] : null,
            ];
            $totalChars += $len;
        }
    }

    if ($allowBroadFallback && empty($labeledPassages)) {
        foreach ($laws as $law) {
            if (!isset($law['id']) || !is_numeric($law['id'])) {
                continue;
            }
            $chunks = $db->getChunksByLawId((int) $law['id']);
            foreach (array_slice($chunks, 0, 2) as $chunk) {
                $content = (string) ($chunk['content'] ?? '');
                if ($content === '') {
                    continue;
                }
                $len = mb_strlen($content, 'UTF-8');
                if ($totalChars + $len > $maxContextChars) {
                    break 2;
                }
                $labeledPassages[] = [
                    'content' => $content,
                    'master_id' => (string) ($law['master_id'] ?? ''),
                    'title' => (string) ($law['title'] ?? ($law['master_id'] ?? 'Zákon')),
                    'section_title' => isset($chunk['section_title']) ? (string) $chunk['section_title'] : null,
                ];
                $totalChars += $len;
            }
            if (count($labeledPassages) >= 40) {
                break;
            }
        }
    }

    // Keď máme len súhrn obdobia, doplň aj AI zhrnutia jednotlivých zákonov pre väčší detail
    $hasOnlyPeriodSummary = count($labeledPassages) === 1
        && isset($labeledPassages[0]['master_id']) && $labeledPassages[0]['master_id'] === '_period_summary';

    if ($allowBroadFallback && (empty($labeledPassages) || $hasOnlyPeriodSummary)) {
        $rows = [];
        foreach (array_slice($laws, 0, 25) as $law) {
            $summary = json_decode((string) ($law['ai_summary'] ?? '{}'), true);
            if (!is_array($summary)) {
                continue;
            }
            $title = (string) ($summary['human_title'] ?? $summary['title'] ?? $law['title'] ?? 'Zákon');
            $paragraph = (string) ($summary['summary_paragraph'] ?? '');
            $parts = [$title . ":\n" . $paragraph];
            $ag = $summary['affected_groups'] ?? [];
            if (is_array($ag) && !empty($ag)) {
                $parts[] = "Zasiahnuté skupiny: " . implode(', ', array_map(static function ($x) {
                    return is_string($x) ? $x : ($x['group'] ?? $x['impact'] ?? json_encode($x));
                }, $ag));
            }
            $pos = $summary['positives'] ?? [];
            if (is_array($pos) && !empty($pos)) {
                $parts[] = "Pozitívne: " . implode(', ', array_map(static function ($x) {
                    return is_string($x) ? $x : ($x['explanation'] ?? $x['positive'] ?? json_encode($x));
                }, $pos));
            }
            $neg = $summary['negatives'] ?? [];
            if (is_array($neg) && !empty($neg)) {
                $parts[] = "Negatívne: " . implode(', ', array_map(static function ($x) {
                    return is_string($x) ? $x : ($x['explanation'] ?? $x['negative'] ?? json_encode($x));
                }, $neg));
            }
            $rows[] = implode("\n", $parts);
        }
        $fallbackContent = trim(implode("\n\n---\n\n", $rows));
        if ($fallbackContent !== '') {
            $len = mb_strlen($fallbackContent, 'UTF-8');
            if ($totalChars + $len <= $maxContextChars) {
                $labeledPassages[] = [
                    'content' => $fallbackContent,
                    'master_id' => '-',
                    'title' => 'AI zhrnutia jednotlivých zákonov obdobia',
                    'section_title' => null,
                ];
            }
        }
    }

    return $labeledPassages;
}

/**
 * @return string[]
 */
function extractKeywords(string $queryText): array
{
    $stopWords = [
        'a', 'ale', 'ani', 'bez', 'by', 'čo', 'co', 'či', 'ci', 'do', 'ide', 'ide?', 'ide?', 'ich', 'je', 'jeho', 'jej',
        'k', 'kam', 'kde', 'keď', 'ked', 'kto', 'ktorá', 'ktoré', 'ktorý', 'ktora', 'ktore', 'ktory', 'ku', 'ma', 'má',
        'na', 'nad', 'ne', 'nech', 'nie', 'no', 'nové', 'nove', 'o', 'od', 'po', 'pod', 'podľa', 'podla', 'pre', 'pri',
        'sa', 'spomína', 'spomina', 's', 'so', 'som', 'sú', 'su', 'ta', 'tak', 'tá', 'tam', 'te', 'ten', 'tento', 'tie',
        'to', 'toto', 'tu', 'ty', 'u', 'už', 'uz', 'v', 'vo', 'vy', 'za', 'že', 'z', 'zo', 'ako', 'aké', 'ake', 'aký',
        'aky', 'aká', 'aka', 'konkrétne', 'konkretne', 'mení', 'meni', 'menia', 'menilo', 'zmenilo', 'zmeny', 'zmena'
    ];
    $normalizedText = mb_strtolower($queryText, 'UTF-8');
    $normalizedText = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $normalizedText) ?? $normalizedText;
    $words = preg_split('/[\s-]+/u', $normalizedText);
    if (!is_array($words)) {
        return [];
    }
    $keywords = array_values(array_unique(array_filter($words, static function ($w) use ($stopWords) {
        if (!is_string($w)) {
            return false;
        }
        $w = trim($w);
        if ($w === '' || mb_strlen($w, 'UTF-8') < 4) {
            return false;
        }
        if (preg_match('/^\d+$/', $w) === 1) {
            return false;
        }
        return !in_array($w, $stopWords, true);
    })));

    return array_slice($keywords, 0, 12);
}

function normalizeSearchText(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');
    $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if (is_string($transliterated) && $transliterated !== '') {
        $text = $transliterated;
    }
    $text = preg_replace('/[^a-z0-9\s-]+/i', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    return $text;
}

/**
 * @param string[] $keywords
 * @return string[]
 */
function expandKeywordVariants(array $keywords): array
{
    $variants = [];
    foreach ($keywords as $keyword) {
        if (!is_string($keyword)) {
            continue;
        }
        $keyword = normalizeSearchText($keyword);
        if ($keyword === '') {
            continue;
        }
        $variants[] = $keyword;

        $stems = [$keyword];
        foreach (['ami', 'ách', 'ach', 'ovej', 'ových', 'ovych', 'ovej', 'ému', 'emu', 'ami', 'och', 'ich', 'ých', 'ymi', 'ého', 'eho', 'ého', 'om', 'em', 'ou', 'ov', 'ev', 'ie', 'ia', 'iu', 'ej', 'ej', 'mi', 'ou', 'a', 'e', 'i', 'í', 'y', 'ý', 'u', 'o'] as $ending) {
            if (mb_strlen($keyword, 'UTF-8') - mb_strlen($ending, 'UTF-8') < 4) {
                continue;
            }
            if (mb_substr($keyword, -mb_strlen($ending, 'UTF-8'), null, 'UTF-8') === $ending) {
                $stems[] = mb_substr($keyword, 0, mb_strlen($keyword, 'UTF-8') - mb_strlen($ending, 'UTF-8'), 'UTF-8');
            }
        }

        foreach ($stems as $stem) {
            $stem = trim($stem);
            if ($stem !== '' && mb_strlen($stem, 'UTF-8') >= 4) {
                $variants[] = $stem;
            }
        }
    }

    return array_values(array_unique($variants));
}

/**
 * @param list<array<string,mixed>> $laws
 * @param string[] $keywords
 * @return list<array<string,mixed>>
 */
function selectRelevantLaws(array $laws, array $keywords, int $maxLaws = 12): array
{
    if (empty($keywords)) {
        return $laws;
    }

    if (count($laws) <= $maxLaws) {
        return $laws;
    }

    $scored = [];
    foreach ($laws as $law) {
        $summary = json_decode((string) ($law['ai_summary'] ?? '{}'), true);
        $titleText = normalizeSearchText(trim((string) ($law['title'] ?? '') . ' ' . (string) ($law['master_id'] ?? '')));
        $summaryTitle = normalizeSearchText(trim((string) ($summary['human_title'] ?? '') . ' ' . implode(' ', is_array($summary['tags'] ?? null) ? $summary['tags'] : [])));
        $summaryText = normalizeSearchText(trim((string) ($summary['summary_paragraph'] ?? '')));

        $score = 0;
        foreach ($keywords as $kw) {
            if ($titleText !== '' && str_contains($titleText, $kw)) {
                $score += 8;
                $score += min(4, substr_count($titleText, $kw));
            }
            if ($summaryTitle !== '' && str_contains($summaryTitle, $kw)) {
                $score += 5;
                $score += min(3, substr_count($summaryTitle, $kw));
            }
            if ($summaryText !== '' && str_contains($summaryText, $kw)) {
                $score += 2;
                $score += min(2, substr_count($summaryText, $kw));
            }
        }

        if ($score > 0) {
            $law['_relevance_score'] = $score;
            $scored[] = $law;
        }
    }

    if (empty($scored)) {
        return [];
    }

    usort($scored, static function ($a, $b) {
        return (int) ($b['_relevance_score'] ?? 0) <=> (int) ($a['_relevance_score'] ?? 0);
    });

    return array_slice($scored, 0, $maxLaws);
}

function isBroadPeriodQuestion(string $question, array $keywords): bool
{
    $q = normalizeSearchText(trim($question));
    if ($q === '') {
        return true;
    }

    if (count($keywords) <= 1) {
        return true;
    }

    return preg_match('/\b(zhrn|sumar|prehlad|co bolo nove|co je nove|ake boli zmeny|novinky v obdobi|najdolezitejsie zmeny)\b/u', $q) === 1;
}

/**
 * @param string[] $keywords
 */
function buildTopicFocus(string $question, array $keywords): string
{
    if (!empty($keywords)) {
        return implode(', ', array_slice($keywords, 0, 4));
    }

    return trim($question);
}

function buildNoRelevantTopicMessage(string $periodLabel, string $topicFocus): string
{
    return "V poskytnutých zákonoch obdobia {$periodLabel} som nenašiel dostatočne relevantné zmeny k téme: {$topicFocus}.";
}

/**
 * @param string[] $keywords
 */
function isAnswerOnTopic(string $answer, array $keywords): bool
{
    $answerLower = normalizeSearchText($answer);
    $checks = 0;

    foreach (array_slice($keywords, 0, 4) as $keyword) {
        if (!is_string($keyword) || $keyword === '' || mb_strlen($keyword, 'UTF-8') < 4) {
            continue;
        }
        $checks++;
        if (str_contains($answerLower, normalizeSearchText($keyword))) {
            return true;
        }
    }

    return $checks === 0;
}

function getPeriodLabel(string $type, int $year, int $value): string
{
    $monthNames = [1=>'január',2=>'február',3=>'marec',4=>'apríl',5=>'máj',6=>'jún',7=>'júl',8=>'august',9=>'september',10=>'október',11=>'november',12=>'december'];
    if ($type === 'month') {
        return ($monthNames[$value] ?? 'mesiac') . ' ' . $year;
    }
    if ($type === 'quarter') {
        return 'Q' . $value . ' ' . $year;
    }
    return (string) $year;
}

function buildPeriodChronologyInstruction(string $type, int $year, int $value): string
{
    if ($type === 'month') {
        return "Sústreď sa prioritne na zmeny patriace do vybraného mesiaca {$value}/{$year}. Najprv uveď posledné aktuálne zmeny z tohto mesiaca. Staršie roky nespomínaj, iba ak sú nevyhnutné ako krátky kontext v maximálne 1-2 vetách na konci.";
    }
    if ($type === 'quarter') {
        return "Sústreď sa prioritne na zmeny patriace do vybraného štvrťroka {$year}. Najprv uveď posledné aktuálne zmeny z tohto štvrťroka. Staršie roky nespomínaj, iba ak sú nevyhnutné ako krátky kontext v maximálne 1-2 vetách na konci.";
    }
    return "Sústreď sa prioritne na posledné aktuálne zmeny patriace do roka {$year}. Odpoveď musí byť chronologicky orientovaná na rok {$year}, nie na historický prehľad starších rokov. Staršie roky spomeň len ak sú nevyhnutné ako stručný kontext na konci.";
}

function isGenericFollowUpQuestion(string $question): bool
{
    $q = mb_strtolower(trim($question), 'UTF-8');
    if ($q === '') {
        return false;
    }

    $hasExplicitTopic = preg_match('/\b(územn|stavebn|zákon|vyhlášk|stavb|plánovan|slovlex|nrsr|portal|urbi|konanie|novela)\b/u', $q) === 1;
    if ($hasExplicitTopic) {
        return false;
    }

    $isShort = mb_strlen($q, 'UTF-8') < 220;
    $isFollowUpStyle = preg_match('/\b(konkrétne|čo to znamená|čo tým myslíš|vieš|v bodoch|rozveď|doplň|a čo|a ako|ktoré zmeny|pre môj článok|pre clanok|zhrň|zhŕň|presnejšie|presnejsie)\b/u', $q) === 1;

    return $isShort && $isFollowUpStyle;
}

function hasExplicitTopicQuestion(string $question): bool
{
    $q = mb_strtolower(trim($question), 'UTF-8');
    if ($q === '') {
        return false;
    }
    return preg_match('/\b(územn|stavebn|zákon|vyhlášk|stavb|plánovan|slovlex|nrsr|portal|urbi|konanie|novela|pozem|výstavb|vystavb)\b/u', $q) === 1;
}

function isPresentationFollowUpQuestion(string $question): bool
{
    $q = mb_strtolower(trim($question), 'UTF-8');
    if ($q === '') {
        return false;
    }

    return preg_match('/\b(v bodoch|bodovo|odrážk|odrazk|pre môj článok|pre clanok|pre článok|zhrň|zhŕň|stručne|strucne|vypíš|vypis|sprav z toho body|sprav body|zjednoduš|prepis|preformuluj|zhrnutie|sumár|sumar)\b/u', $q) === 1;
}

function getLastUserQuestion(array $history): string
{
    for ($i = count($history) - 1; $i >= 0; $i--) {
        $item = $history[$i] ?? null;
        if (!is_array($item)) {
            continue;
        }
        if (($item['role'] ?? '') !== 'user') {
            continue;
        }
        $content = trim((string) ($item['content'] ?? ''));
        if ($content !== '') {
            return $content;
        }
    }
    return '';
}

function getLastAssistantAnswer(array $history): string
{
    for ($i = count($history) - 1; $i >= 0; $i--) {
        $item = $history[$i] ?? null;
        if (!is_array($item)) {
            continue;
        }
        if (($item['role'] ?? '') !== 'assistant') {
            continue;
        }
        $content = trim((string) ($item['content'] ?? ''));
        if ($content !== '') {
            return $content;
        }
    }
    return '';
}

function buildPreviousContextBlock(array $history): string
{
    $lastUserQuestion = getLastUserQuestion($history);
    $lastAssistantAnswer = getLastAssistantAnswer($history);
    if ($lastUserQuestion === '' && $lastAssistantAnswer === '') {
        return '';
    }

    $parts = ["Kontext predchádzajúcej výmeny v tom istom chate:"];
    if ($lastUserQuestion !== '') {
        $parts[] = "Predchádzajúca otázka používateľa: {$lastUserQuestion}";
    }
    if ($lastAssistantAnswer !== '') {
        $assistantShort = mb_substr($lastAssistantAnswer, 0, 1600, 'UTF-8');
        if (mb_strlen($lastAssistantAnswer, 'UTF-8') > 1600) {
            $assistantShort .= '...';
        }
        $parts[] = "Predchádzajúca odpoveď asistenta: {$assistantShort}";
    }

    return implode("\n", $parts);
}

function buildConversationTranscriptBlock(array $history, int $maxMessages = 6): string
{
    if (empty($history)) {
        return '';
    }

    $slice = array_slice($history, -$maxMessages);
    $lines = [];
    foreach ($slice as $item) {
        if (!is_array($item)) {
            continue;
        }
        $role = ($item['role'] ?? '') === 'assistant' ? 'Asistent' : 'Používateľ';
        $content = trim((string) ($item['content'] ?? ''));
        if ($content === '') {
            continue;
        }
        if (mb_strlen($content, 'UTF-8') > 1800) {
            $content = mb_substr($content, 0, 1800, 'UTF-8') . '...';
        }
        $lines[] = $role . ': ' . $content;
    }

    if (empty($lines)) {
        return '';
    }

    return "Doterajší obsah tej istej konverzácie:\n" . implode("\n\n", $lines);
}

function getTopicAnchorQuestion(string $question, array $history): string
{
    if (hasExplicitTopicQuestion($question)) {
        return trim($question);
    }

    for ($i = count($history) - 1; $i >= 0; $i--) {
        $item = $history[$i] ?? null;
        if (!is_array($item) || (($item['role'] ?? '') !== 'user')) {
            continue;
        }
        $content = trim((string) ($item['content'] ?? ''));
        if ($content === '') {
            continue;
        }
        if (hasExplicitTopicQuestion($content) || !isGenericFollowUpQuestion($content)) {
            return $content;
        }
    }

    return getLastUserQuestion($history);
}

function buildResolvedQuestion(string $question, array $history): string
{
    $topicAnchor = getTopicAnchorQuestion($question, $history);
    if ($topicAnchor === '' || !isGenericFollowUpQuestion($question)) {
        return $question;
    }

    return "Téma konverzácie: {$topicAnchor}\n"
        . "Používateľ stále pokračuje v tej istej téme.\n"
        . "Aktuálna doplňujúca otázka: {$question}";
}

function shouldUseWebFallback(string $question, string $answer): bool
{
    $answerLower = mb_strtolower($answer, 'UTF-8');
    $questionLower = mb_strtolower($question, 'UTF-8');

    $explicitMissing = str_contains($answerLower, 'nie je v poskytnutých zákonoch obdobia explicitne uvedené')
        || str_contains($answerLower, 'nie je v texte uvedené')
        || str_contains($answerLower, 'informácia nie je');

    $asksForDetail = preg_match('/\b(konkrétne|presne|aké|ktoré|ako presne|čo sa mení|čo sa meni|čo sa menilo|čo sa zmenilo|čo sa nové zmenilo|čo sa nove zmenilo|čo sa nové|čo sa nove|aké sú zmeny|aké zmeny|zmeny v|v čom|na základe čoho|čo je nové|čo je nove|nové v|nove v|novinky|čo je nové na|čo je nove na)\b/u', $questionLower) === 1;
    $asksForStructuredOutput = preg_match('/\b(v bodoch|bodovo|odrážk|odrazk|vypíš|vypis|zhrň|zhŕň|sumár|sumar|pre môj článok|pre môj clanok|pre článok|pre clanok)\b/u', $questionLower) === 1;
    $summaryOnly = str_contains($answerLower, 'zdroj: zhrnutie obdobia')
        || str_contains($answerLower, 'zhrnutie obdobia');
    $hasLawReference = preg_match('/\b\d{1,4}\/\d{4}\b/u', $answer) === 1
        || str_contains($answer, '§')
        || str_contains($answer, 'čl.');
    $tooShortForDetail = mb_strlen(trim($answer), 'UTF-8') < 700;
    $tooGeneric = str_contains($answerLower, 'došlo k zmene')
        || str_contains($answerLower, 'táto zmena')
        || str_contains($answerLower, 'môže byť problém')
        || str_contains($answerLower, 'je súčasťou legislatívnych zmien')
        || str_contains($answerLower, 'na základe poskytnutých úryvkov')
        || str_contains($answerLower, 'v oblasti')
        || str_contains($answerLower, 'konkrétne,')
        || str_contains($answerLower, 'tieto zmeny sú súčasťou širších')
        || str_contains($answerLower, 'upravuje štruktúru a prevádzku');

    // Ak používateľ pýtal na konkrétnu tému a dostal všeobecnú odpoveď, doplň z webu
    $askedSpecificTopic = hasExplicitTopicQuestion($question);

    return $explicitMissing
        || ($asksForDetail && (!$hasLawReference || $tooShortForDetail || $tooGeneric))
        || ($asksForStructuredOutput && ($tooShortForDetail || $tooGeneric))
        || ($summaryOnly && (!$hasLawReference || $tooGeneric))
        || ($tooGeneric && $askedSpecificTopic);
}

function stripInlineSourcesFromAnswer(string $answer): string
{
    $clean = $answer;

    // Remove markdown links like [title](https://...)
    $clean = preg_replace('/\[([^\]]+)\]\(https?:\/\/[^)\s]+\)/u', '$1', $clean) ?? $clean;

    // Remove bare URLs
    $clean = preg_replace('/https?:\/\/[^\s)]+/u', '', $clean) ?? $clean;

    // Remove parenthetical source mentions like (financekompas.sk), (Zdroj: ...)
    $clean = preg_replace('/\(\s*(?:zdroj[e]?\s*:)?\s*[a-z0-9.-]+\.[a-z]{2,}[^)]*\)/iu', '', $clean) ?? $clean;

    // Remove source sections inside the answer body; sources are rendered separately in UI
    $clean = preg_replace('/(^|\n)\s*Zdroje\s*:\s*.+$/imu', '$1', $clean) ?? $clean;
    $clean = preg_replace('/(^|\n)\s*Zdroj\s*:\s*.+$/imu', '$1', $clean) ?? $clean;

    // Remove empty parentheses left after URL stripping
    $clean = preg_replace('/\(\s*\)/u', '', $clean) ?? $clean;

    // Normalize blank lines created by stripping
    $clean = preg_replace("/\n{3,}/", "\n\n", $clean) ?? $clean;
    $clean = preg_replace('/[ \t]{2,}/u', ' ', $clean) ?? $clean;

    return trim($clean);
}

function cleanSnippet(string $text, int $maxLen = 500): string
{
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    if (mb_strlen($text, 'UTF-8') <= $maxLen) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $maxLen, 'UTF-8')) . '...';
}

/**
 * @param list<array<string,mixed>> $relevantLaws
 * @param list<array{content:string,master_id:string,title:string,section_title:?string}> $labeledPassages
 */
function buildInternalFallbackAnswer(
    string $periodLabel,
    string $topicFocus,
    bool $isBroadQuestion,
    array $relevantLaws,
    array $labeledPassages,
    ?array $periodSummary
): string {
    $parts = [];
    $parts[] = "Momentálne sa nepodarilo dokončiť AI analýzu ani webové doplnenie, preto uvádzam aspoň najrelevantnejšie informácie priamo z interných podkladov pre obdobie {$periodLabel}.";

    if ($isBroadQuestion && $periodSummary !== null) {
        $summary = trim((string) ($periodSummary['changes'] ?? $periodSummary['summary_paragraph'] ?? ''));
        if ($summary !== '') {
            $parts[] = cleanSnippet($summary, 900);
        }
    }

    $lawTitles = [];
    foreach (array_slice($relevantLaws, 0, 4) as $law) {
        $title = trim((string) ($law['title'] ?? $law['master_id'] ?? ''));
        if ($title !== '') {
            $lawTitles[] = $title;
        }
    }
    if (!empty($lawTitles)) {
        $parts[] = "K téme `{$topicFocus}` sú v tomto období najrelevantnejšie najmä tieto podklady: " . implode('; ', $lawTitles) . '.';
    }

    $passageSnippets = [];
    foreach ($labeledPassages as $passage) {
        if (($passage['master_id'] ?? '') === '_period_summary') {
            continue;
        }
        $title = trim((string) ($passage['title'] ?? 'Zákon'));
        $content = cleanSnippet((string) ($passage['content'] ?? ''), 420);
        if ($content === '') {
            continue;
        }
        $passageSnippets[] = $title . ': ' . $content;
        if (count($passageSnippets) >= 2) {
            break;
        }
    }
    if (!empty($passageSnippets)) {
        $parts[] = implode("\n\n", $passageSnippets);
    }

    if (count($parts) === 1) {
        $parts[] = "V interných podkladoch sa nepodarilo zostaviť dostatočne presný výber k téme `{$topicFocus}`.";
    }

    $parts[] = "Toto je núdzový výstup z interných zdrojov; nejde o právne poradenstvo.";
    return implode("\n\n", $parts);
}

try {
    Config::load();
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration error.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$db = new Database(Config::get('DB_PATH', 'data/sentinel.db') ?: 'data/sentinel.db');
$auth = new Auth($db);

if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Musíte byť prihlásený.']);
    exit;
}

$payload = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

$type = (string) ($payload['type'] ?? $_POST['type'] ?? '');
$year = (int) ($payload['year'] ?? $_POST['year'] ?? 0);
$value = (int) ($payload['value'] ?? $_POST['value'] ?? 0);
$question = trim((string) ($payload['question'] ?? $_POST['question'] ?? ''));

if (!in_array($type, ['month', 'quarter', 'year'], true) || $year < 2000 || $year > 2100) {
    http_response_code(400);
    echo json_encode(['error' => 'Neplatné obdobie.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($type === 'month' && ($value < 1 || $value > 12)) {
    http_response_code(400);
    echo json_encode(['error' => 'Neplatný mesiac.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($type === 'quarter' && ($value < 1 || $value > 4)) {
    http_response_code(400);
    echo json_encode(['error' => 'Neplatný štvrťrok.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($type === 'year') {
    $value = 0;
}
if ($question === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Chýba otázka.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (mb_strlen($question, 'UTF-8') > 1000) {
    http_response_code(400);
    echo json_encode(['error' => 'Otázka je príliš dlhá.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$laws = $db->getLawsForPeriod($type, $year, $value);
if (empty($laws)) {
    http_response_code(404);
    echo json_encode(['error' => 'Pre zvolené obdobie nie sú dostupné zákonné zdroje.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$keywords = extractKeywords($question);
$keywordVariants = expandKeywordVariants($keywords);
$isBroadQuestion = isBroadPeriodQuestion($question, $keywords);
$relevantLaws = selectRelevantLaws($laws, $keywordVariants);
$periodSummary = $db->getPeriodSummary($type, $year, $value);
$labeledPassages = buildPeriodLabeledPassages(
    $db,
    $relevantLaws,
    $keywordVariants,
    $periodSummary,
    $isBroadQuestion,
    $isBroadQuestion || !empty($relevantLaws)
);

$openaiKey = Config::get('OPENAI_API_KEY');
if (empty($openaiKey) || $openaiKey === 'your_openai_api_key_here') {
    http_response_code(500);
    echo json_encode(['error' => 'OpenAI API nie je nakonfigurované.']);
    exit;
}

$logPath = Config::get('LOG_PATH', 'storage/logs');
if (!str_starts_with($logPath, '/')) {
    $logPath = dirname(__DIR__) . '/' . $logPath;
}
$logger = new Logger($logPath . '/app.log');
$aiClient = new OpenAIClient(
    $openaiKey,
    Config::get('OPENAI_MODEL', 'gpt-4o'),
    (int) Config::get('OPENAI_MAX_TOKENS', '4000'),
    $logger
);
$periodLabel = getPeriodLabel($type, $year, $value);
$chronologyInstruction = buildPeriodChronologyInstruction($type, $year, $value);
$useWebSearch = filter_var(Config::get('GLOBAL_CHAT_WEB_SEARCH', 'true'), FILTER_VALIDATE_BOOLEAN);
$globalChatModel = Config::get('GLOBAL_CHAT_MODEL', 'gpt-4o-search-preview');
$topicFocus = buildTopicFocus($question, $keywords);
$internalNoMatchMessage = buildNoRelevantTopicMessage($periodLabel, $topicFocus);
$hasRelevantInternalPassages = !empty($labeledPassages);

try {
    $logger->info('Period chat retrieval', [
        'period' => $periodLabel,
        'question' => $question,
        'keywords' => $keywords,
        'keyword_variants' => $keywordVariants,
        'broad_question' => $isBroadQuestion,
        'relevant_laws' => count($relevantLaws),
        'labeled_passages' => count($labeledPassages),
    ]);

    $currentYear = (int) date('Y');
    $directPrompt = "Obdobie: {$periodLabel}\n"
        . "Otázka používateľa: {$question}\n"
        . "Presná téma otázky: {$topicFocus}\n\n"
        . "Pokyny:\n"
        . "1) Každú otázku spracuj samostatne, bez kontextu predchádzajúcej konverzácie.\n"
        . "2) Odpoveď postav prioritne na interných právnych podkladoch, ktoré dostaneš.\n"
        . "3) Zároveň vykonaj dôkladné webové vyhľadávanie a overenie. Ak treba, urob viacero webových hľadaní s rôznymi formuláciami tej istej témy.\n"
        . "4) Uprednostni oficiálne a autoritatívne zdroje: Slov-Lex, NR SR, ministerstvá, Úrad pre územné plánovanie a výstavbu, dôvodové správy, oficiálne portály štátu a seriózne odborné právne zdroje.\n"
        . "5) Prioritne používaj aktuálne zdroje z rokov {$currentYear} a " . ($currentYear - 1) . ". Staršie zdroje používaj len ak sú stále právne relevantné.\n"
        . "6) Odpovedz len k presnej téme otázky. Ak sa v podkladoch nachádza viac tém, nepresmeruj odpoveď na inú oblasť.\n"
        . "7) Uvádzaj konkrétne fakty: názvy zákonov, čísla predpisov, dátumy účinnosti, konkrétne zmeny a koho sa týkajú.\n"
        . "8) " . $chronologyInstruction . "\n"
        . "9) Žiadne všeobecné frázy, žiadne placeholders, žiadne domnienky bez zdroja.\n"
        . "10) Jasne odlíš, čo je z interných zákonných podkladov a čo je doplnenie z webu.\n"
        . "11) Odpoveď má byť odborná, kvalitná, hodnotná a po slovensky.\n"
        . "12) Ak tému nevieš spoľahlivo potvrdiť ani z interných podkladov, ani z kvalitného webového vyhľadania, povedz to otvorene.";

    if (!$hasRelevantInternalPassages) {
        $directPrompt .= "\n\nInterné zistenie: {$internalNoMatchMessage}";
    }

    $webResult = $aiClient->answerFromPassagesWithWebSearch(
        $labeledPassages,
        $directPrompt,
        [],
        $globalChatModel,
        'high'
    );

    $answer = stripInlineSourcesFromAnswer((string) ($webResult['answer'] ?? ''));
    if ($answer === '') {
        $answer = $hasRelevantInternalPassages
            ? buildInternalFallbackAnswer($periodLabel, $topicFocus, $isBroadQuestion, $relevantLaws, $labeledPassages, $periodSummary)
            : $internalNoMatchMessage;
    }
    if (!$isBroadQuestion && !isAnswerOnTopic($answer, $keywordVariants)) {
        $answer = $hasRelevantInternalPassages
            ? buildInternalFallbackAnswer($periodLabel, $topicFocus, $isBroadQuestion, $relevantLaws, $labeledPassages, $periodSummary)
            : $internalNoMatchMessage;
    }

    $citations = is_array($webResult['citations'] ?? null) ? $webResult['citations'] : [];
    $source = !empty($webResult['used_web_search']) ? 'web_search' : 'fallback';

    $logger->info('Period chat direct-web result', [
        'has_relevant_internal_passages' => $hasRelevantInternalPassages,
        'used_web_search' => !empty($webResult['used_web_search']),
        'citations_count' => count($citations),
        'answer_preview' => mb_substr($answer, 0, 400, 'UTF-8'),
    ]);

    $payload = ['answer' => $answer, 'source' => $source];
    if (!empty($citations)) {
        $payload['citations'] = $citations;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    $logger->error("Period chat error: " . $e->getMessage());
    $fallbackAnswer = buildInternalFallbackAnswer(
        $periodLabel,
        $topicFocus,
        $isBroadQuestion,
        $relevantLaws,
        $labeledPassages,
        $periodSummary
    );
    echo json_encode([
        'answer' => $fallbackAnswer,
        'source' => 'fallback',
        'warning' => 'AI alebo webové doplnenie bolo dočasne nedostupné.',
    ], JSON_UNESCAPED_UNICODE);
}
