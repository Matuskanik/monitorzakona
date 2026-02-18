<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;

Config::load();
$db = new Database(Config::get('DB_PATH', 'data/sentinel.db'));

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$mp = $db->getParliamentMpById($id);
if (!$mp) {
    http_response_code(404);
    echo 'Poslanec nebol najdeny.';
    exit;
}
$recentVotes = $db->getRecentVotesForMp($id, 40);
$stats = json_decode((string)($mp['stats_json'] ?? '{}'), true);
if (!is_array($stats)) {
    $stats = [];
}
?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title><?php echo htmlspecialchars($mp['full_name']); ?></title>
    <link rel="stylesheet" href="css/liquid-glass.css">
    <script>(function(){if(localStorage.getItem('darkMode')==='1')document.documentElement.classList.add('dark-mode');})();</script>
    <style>
        .lg-profile{display:grid;grid-template-columns:180px 1fr;gap:18px;align-items:start}
        .lg-photo{width:160px;height:200px;object-fit:cover;border-radius:10px;background:var(--surface-2);border:1px solid var(--glass-border)}
        .lg-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:14px 0}
        .lg-box{padding:14px 16px}
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
    <a class="lg-back-link" href="parliament.php">← Späť na prehľad poslancov</a>
    <div class="lg-profile">
        <div>
            <?php if (!empty($mp['photo_url'])): ?>
                <img class="lg-photo" src="<?php echo htmlspecialchars($mp['photo_url']); ?>" alt="<?php echo htmlspecialchars($mp['full_name']); ?>">
            <?php else: ?>
                <div class="lg-photo"></div>
            <?php endif; ?>
        </div>
        <div>
            <h1 class="lg-title"><?php echo htmlspecialchars($mp['full_name']); ?></h1>
            <p class="lg-body"><?php echo htmlspecialchars((string)($mp['title'] ?? '')); ?> | Klub: <?php echo htmlspecialchars((string)($mp['club'] ?? '-')); ?></p>
            <p class="lg-body">Strana: <?php echo htmlspecialchars((string)($mp['party'] ?? '-')); ?> | Kraj: <?php echo htmlspecialchars((string)($mp['district'] ?? '-')); ?></p>
            <p class="lg-info" style="margin-top:10px;"><?php echo htmlspecialchars((string)($mp['card_text'] ?? 'Karta poslanca sa vygeneruje po prvom spracovaní cron skriptom.')); ?></p>
        </div>
    </div>

    <div class="lg-cards">
        <div class="lg-card lg-box"><strong>Dochádzka</strong><br><?php echo number_format((float)($stats['attendance_pct'] ?? 0), 1, ',', ' '); ?>%</div>
        <div class="lg-card lg-box"><strong>Hlasovania spolu</strong><br><?php echo (int)($stats['total_votes'] ?? 0); ?></div>
        <div class="lg-card lg-box"><strong>Za</strong><br><?php echo (int)($stats['votes_for'] ?? 0); ?></div>
        <div class="lg-card lg-box"><strong>Proti</strong><br><?php echo (int)($stats['votes_against'] ?? 0); ?></div>
        <div class="lg-card lg-box"><strong>Zdržal sa</strong><br><?php echo (int)($stats['votes_abstain'] ?? 0); ?></div>
        <div class="lg-card lg-box"><strong>Neprítomný</strong><br><?php echo (int)($stats['votes_absent'] ?? 0); ?></div>
    </div>

    <h2 class="lg-section-title">Posledné hlasovania poslanca</h2>
    <div class="lg-table-wrap">
    <table class="lg-table">
        <thead>
            <tr>
                <th>Dátum</th>
                <th>Hlasovanie</th>
                <th>Hlas</th>
                <th>Výsledok</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($recentVotes as $vote): ?>
            <tr>
                <td><?php echo htmlspecialchars(($vote['voting_date'] ?? '-') . ' ' . ($vote['voting_time'] ?? '')); ?></td>
                <td><a href="parliament-voting.php?id=<?php echo (int)$vote['voting_id']; ?>"><?php echo htmlspecialchars($vote['title']); ?></a></td>
                <td><?php echo htmlspecialchars((string)($vote['vote_label'] ?? '-')); ?></td>
                <td><?php echo htmlspecialchars((string)($vote['result_text'] ?? $vote['result'] ?? '-')); ?></td>
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
