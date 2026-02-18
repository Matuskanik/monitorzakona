#!/usr/bin/env php
<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\Logger;
use App\ParliamentAnalyzer;
use App\ParliamentScraper;

try {
    Config::load();
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

$logger = new Logger(Config::get('LOG_PATH', 'storage/logs') . '/app.log');
$db = new Database(Config::get('DB_PATH', 'data/sentinel.db'));
$scraper = new ParliamentScraper(
    $logger,
    (int)Config::get('REQUEST_DELAY_SECONDS', '2'),
    (int)Config::get('MAX_RETRIES', '3'),
    (int)Config::get('RETRY_DELAY_SECONDS', '4')
);
$analyzer = new ParliamentAnalyzer();

$votingLimit = (int)($argv[1] ?? 0);      // 0 = no limit (all history in term)
$profileLimit = (int)($argv[2] ?? 220);   // 0 = skip profile refresh
$detailLimit = (int)($argv[3] ?? 120);    // 0 = no detail fetch; -1 = all details
$term = (int)Config::get('NR_SR_TERM', '9');
$maxVotingPages = (int)Config::get('NR_SR_VOTING_MAX_PAGES', '220');
$termStartDate = Config::get('NR_SR_TERM_START_DATE', '2023-10-01');

$votingListUrl = Config::get(
    'NR_SR_VOTING_LIST_URL',
    'https://www.nrsr.sk/web/Default.aspx?sid=search&Module=Hlas&text=hlasovanie'
);
$mpListUrl = Config::get(
    'NR_SR_MP_LIST_URL',
    'https://www.nrsr.sk/web/Default.aspx?sid=poslanci/zoznam_abc&CisObdobia=' . $term
);

echo "Parliament cron started\n";

// 1) Voting list + details (all historical pages for current term)
$votingsById = [];
$emptyPages = 0;
for ($pageIndex = 1; $pageIndex <= max(1, $maxVotingPages); $pageIndex++) {
    $pageUrl = $votingListUrl;
    if (!str_contains($pageUrl, 'PageIndex=')) {
        $pageUrl .= (str_contains($pageUrl, '?') ? '&' : '?') . 'PageIndex=' . $pageIndex;
    } else {
        $pageUrl = preg_replace('/PageIndex=\d+/', 'PageIndex=' . $pageIndex, $pageUrl);
    }

    $pageHtml = $scraper->fetchPage($pageUrl);
    $pageVotings = $scraper->parseVotingList($pageHtml);
    if (empty($pageVotings)) {
        $emptyPages++;
        if ($emptyPages >= 3) {
            break;
        }
        continue;
    }
    $emptyPages = 0;

    $allOlderThanTermStart = true;
    foreach ($pageVotings as $pv) {
        $date = $pv['voting_date'] ?? null;
        if ($date === null || $date === '' || $date >= $termStartDate) {
            $allOlderThanTermStart = false;
        }
        if ($date !== null && $date !== '' && $date < $termStartDate) {
            continue;
        }
        $votingsById[$pv['nrsr_voting_id']] = $pv;
    }

    if ($allOlderThanTermStart) {
        break;
    }
}

$votings = array_values($votingsById);
usort($votings, static function (array $a, array $b): int {
    $ad = ($a['voting_date'] ?? '') . ' ' . ($a['voting_time'] ?? '');
    $bd = ($b['voting_date'] ?? '') . ' ' . ($b['voting_time'] ?? '');
    return strcmp($bd, $ad);
});
if ($votingLimit > 0) {
    $votings = array_slice($votings, 0, $votingLimit);
}

$processedVotings = 0;
$skippedExistingVotings = 0;
$detailedVotings = 0;
$index = 0;
foreach ($votings as $voting) {
    $index++;
    try {
        $existing = $db->findParliamentVotingByNrsrId((string)$voting['nrsr_voting_id']);
        $alreadyDetailed = $existing && !empty($existing['summary_text']) && $existing['votes_for'] !== null;
        if ($alreadyDetailed) {
            $skippedExistingVotings++;
            continue;
        }

        $payload = [
            'nrsr_voting_id' => $voting['nrsr_voting_id'],
            'session_number' => $voting['session_number'],
            'voting_number' => $voting['voting_number'],
            'title' => $voting['title'],
            'voting_date' => $voting['voting_date'],
            'voting_time' => $voting['voting_time'],
            'result' => null,
            'result_text' => null,
            'present_count' => null,
            'votes_for' => null,
            'votes_against' => null,
            'votes_abstain' => null,
            'votes_absent' => null,
            'votes_did_not_vote' => null,
            'summary_text' => 'Základný historický záznam. Detail sa doplní pri otvorení alebo pri ďalšej synchronizácii.',
            'source_url' => $voting['source_url'],
        ];
        $votingId = $db->saveOrUpdateParliamentVoting($payload);

        $shouldFetchDetail = $detailLimit < 0 || ($detailLimit > 0 && $index <= $detailLimit);
        if ($shouldFetchDetail) {
            $detailHtml = $scraper->fetchPage($voting['source_url']);
            $detail = $scraper->parseVotingDetail($detailHtml);

            $payload['title'] = $detail['title'] ?: $voting['title'];
            $payload['result'] = $detail['result'];
            $payload['result_text'] = $detail['result_text'];
            $payload['present_count'] = $detail['counts']['present_count'];
            $payload['votes_for'] = $detail['counts']['votes_for'];
            $payload['votes_against'] = $detail['counts']['votes_against'];
            $payload['votes_abstain'] = $detail['counts']['votes_abstain'];
            $payload['votes_absent'] = $detail['counts']['votes_absent'];
            $payload['votes_did_not_vote'] = $detail['counts']['votes_did_not_vote'];
            $payload['summary_text'] = $analyzer->buildVotingSummary($payload);
            $votingId = $db->saveOrUpdateParliamentVoting($payload);
            $db->replaceVotesForVoting($votingId, $detail['votes']);
            $detailedVotings++;
        }

        $processedVotings++;
    } catch (\Throwable $e) {
        $logger->error('Failed parliament voting', ['id' => $voting['nrsr_voting_id'], 'error' => $e->getMessage()]);
    }
}

// 2) MP list + profiles
$mps = [];
if ($profileLimit !== 0) {
    $mpsHtml = $scraper->fetchPage($mpListUrl);
    $mps = $scraper->parseMpsList($mpsHtml);
    if ($profileLimit > 0) {
        $mps = array_slice($mps, 0, $profileLimit);
    }
}

$processedMps = 0;
foreach ($mps as $mp) {
    try {
        $mpId = $db->saveOrUpdateParliamentMp([
            'nrsr_id' => $mp['nrsr_id'],
            'full_name' => $mp['full_name'],
            'profile_url' => $mp['profile_url'],
        ]);

        $profileHtml = $scraper->fetchPage($mp['profile_url']);
        $profile = $scraper->parseMpProfile($profileHtml);
        $mpData = array_merge($mp, $profile, ['nrsr_id' => $mp['nrsr_id'], 'full_name' => $mp['full_name'], 'profile_url' => $mp['profile_url']]);
        $mpId = $db->saveOrUpdateParliamentMp($mpData);

        $row = $db->getParliamentMpById($mpId) ?: ['full_name' => $mp['full_name']];
        $stats = json_decode((string)($row['stats_json'] ?? '{}'), true);
        if (!is_array($stats)) {
            $stats = [];
        }
        $card = $analyzer->buildMpCard(array_merge($row, $stats));
        $db->refreshParliamentMpStatsAndCard($mpId, $card);
        $processedMps++;
    } catch (\Throwable $e) {
        $logger->error('Failed parliament MP', ['id' => $mp['nrsr_id'], 'error' => $e->getMessage()]);
    }
}

// 3) Monthly report
$year = (int)date('Y');
$month = (int)date('m');
$stats = $db->buildMonthlyVotingStats($year, $month);
$summary = sprintf(
    "Mesačný report %02d/%d: hlasovaní %d, schválené %d, neschválené %d, priemerná prítomnosť %.1f.",
    $month,
    $year,
    $stats['total_votings'],
    $stats['approved'],
    $stats['rejected'],
    $stats['avg_present']
);
$db->saveMonthlyParliamentReport($year, $month, $summary, $stats);

echo "Completed: {$processedVotings} votings ({$detailedVotings} detailed, {$skippedExistingVotings} already up-to-date), {$processedMps} MPs\n";
