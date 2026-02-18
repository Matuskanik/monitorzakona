<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\Logger;
use App\ParliamentAnalyzer;
use App\ParliamentScraper;

Config::load();
$db = new Database(Config::get('DB_PATH', 'data/sentinel.db'));

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$voting = $db->getParliamentVotingById($id);
if (!$voting) {
    http_response_code(404);
    echo 'Hlasovanie nebolo najdene.';
    exit;
}

$votes = $db->getParliamentVotesForVoting($id);

// Lazy detail fetch for historical rows imported without full vote breakdown.
if (empty($votes) && !empty($voting['source_url'])) {
    try {
        $logger = new Logger(Config::get('LOG_PATH', 'storage/logs') . '/app.log');
        $scraper = new ParliamentScraper($logger, 0, 2, 2);
        $analyzer = new ParliamentAnalyzer();

        $detailHtml = $scraper->fetchPage($voting['source_url']);
        $detail = $scraper->parseVotingDetail($detailHtml);

        $payload = [
            'nrsr_voting_id' => $voting['nrsr_voting_id'],
            'session_number' => $voting['session_number'],
            'voting_number' => $voting['voting_number'],
            'title' => $detail['title'] ?: $voting['title'],
            'voting_date' => $voting['voting_date'],
            'voting_time' => $voting['voting_time'],
            'result' => $detail['result'],
            'result_text' => $detail['result_text'],
            'present_count' => $detail['counts']['present_count'],
            'votes_for' => $detail['counts']['votes_for'],
            'votes_against' => $detail['counts']['votes_against'],
            'votes_abstain' => $detail['counts']['votes_abstain'],
            'votes_absent' => $detail['counts']['votes_absent'],
            'votes_did_not_vote' => $detail['counts']['votes_did_not_vote'],
            'summary_text' => '',
            'source_url' => $voting['source_url'],
        ];
        $payload['summary_text'] = $analyzer->buildVotingSummary($payload);

        $votingId = $db->saveOrUpdateParliamentVoting($payload);
        $db->replaceVotesForVoting($votingId, $detail['votes']);

        $voting = $db->getParliamentVotingById($id) ?: $voting;
        $votes = $db->getParliamentVotesForVoting($id);
    } catch (\Throwable $e) {
        // Keep page functional even if lazy fetch fails.
    }
}
?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title><?php echo htmlspecialchars($voting['title']); ?></title>
    <link rel="stylesheet" href="css/liquid-glass.css">
    <script>(function(){if(localStorage.getItem('darkMode')==='1')document.documentElement.classList.add('dark-mode');})();</script>
    <style>
        .lg-meta-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin:14px 0}
        .lg-meta-box{padding:14px 16px}
        .lg-table-wrap{overflow:auto;border:1px solid var(--glass-border);border-radius:14px;background:var(--glass-bg)}
        .lg-table{width:100%;border-collapse:collapse}
        .lg-table th,.lg-table td{padding:10px 12px;border-bottom:1px solid var(--glass-border);text-align:left;font-size:.92rem;color:var(--text-secondary)}
        .lg-table th{font-weight:600;color:var(--text-primary);position:sticky;top:0;background:var(--surface-1)}
    </style>
</head>
<body>
<div class="lg-dark-toggle" id="darkModeToggle" title="Tmavý režim">
    <div class="lg-dark-toggle-row">
        <span class="label-off">OFF</span>
        <div class="lg-dark-switch" id="darkModeSwitch" role="switch" aria-checked="false" aria-label="Tmavý režim"></div>
        <span class="label-on">ON</span>
    </div>
    <span class="lg-dark-label">Tmavý režim</span>
</div>
<div class="lg-container">
    <a class="lg-back-link" href="parliament.php">← Späť na prehľad hlasovaní</a>
    <h1 class="lg-title"><?php echo htmlspecialchars($voting['title']); ?></h1>
    <p class="lg-body" style="margin-top:10px;"><?php echo htmlspecialchars($voting['summary_text'] ?? ''); ?></p>

    <div class="lg-meta-grid">
        <div class="lg-card lg-meta-box"><strong>Dátum:</strong><br><?php echo htmlspecialchars(($voting['voting_date'] ?? '-') . ' ' . ($voting['voting_time'] ?? '')); ?></div>
        <div class="lg-card lg-meta-box"><strong>Výsledok:</strong><br><?php echo htmlspecialchars($voting['result_text'] ?? ($voting['result'] ?? '-')); ?></div>
        <div class="lg-card lg-meta-box"><strong>Za / Proti:</strong><br><?php echo (int)($voting['votes_for'] ?? 0); ?> / <?php echo (int)($voting['votes_against'] ?? 0); ?></div>
        <div class="lg-card lg-meta-box"><strong>Zdržal sa / Neprítomní:</strong><br><?php echo (int)($voting['votes_abstain'] ?? 0); ?> / <?php echo (int)($voting['votes_absent'] ?? 0); ?></div>
    </div>

    <h2 class="lg-section-title">Kto ako hlasoval</h2>
    <div class="lg-table-wrap">
    <table class="lg-table">
        <thead>
            <tr>
                <th>Poslanec</th>
                <th>Klub</th>
                <th>Hlas</th>
                <th>Kód</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($votes as $vote): ?>
            <tr>
                <td><a href="parliament-mp.php?id=<?php echo (int)$vote['mp_id']; ?>"><?php echo htmlspecialchars($vote['full_name']); ?></a></td>
                <td><?php echo htmlspecialchars((string)($vote['club'] ?? '-')); ?></td>
                <td><?php echo htmlspecialchars((string)($vote['vote_label'] ?? '-')); ?></td>
                <td><?php echo htmlspecialchars((string)($vote['vote_code'] ?? '-')); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<script>
(function() {
    var KEY = 'darkMode';
    var el = document.documentElement;
    var sw = document.getElementById('darkModeSwitch');
    var tg = document.getElementById('darkModeToggle');
    function isOn() { return localStorage.getItem(KEY) === '1'; }
    function apply(on) {
        if (on) { el.classList.add('dark-mode'); sw.classList.add('on'); sw.setAttribute('aria-checked', 'true'); }
        else { el.classList.remove('dark-mode'); sw.classList.remove('on'); sw.setAttribute('aria-checked', 'false'); }
    }
    function toggle() {
        var on = !isOn();
        localStorage.setItem(KEY, on ? '1' : '0');
        apply(on);
    }
    apply(isOn());
    if (sw) sw.addEventListener('click', toggle);
    if (tg) tg.addEventListener('click', function(e) { if (e.target !== sw) toggle(); });
})();
</script>
</body>
</html>
