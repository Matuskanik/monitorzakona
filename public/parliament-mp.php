<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\PartyColors;

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
$mediaProfile = json_decode((string)($mp['media_profile_json'] ?? '{}'), true);
if (!is_array($mediaProfile)) {
    $mediaProfile = [];
}

// Extract markdown links [text](url) from text, return clean text + links array
function parseMediaText(string $text): array {
    $links = [];
    $clean = preg_replace_callback('/\[([^\]]*)\]\(([^)]+)\)/u', static function ($m) use (&$links) {
        $links[] = ['label' => trim($m[1]), 'url' => trim($m[2])];
        return '';
    }, $text);
    $clean = preg_replace('/\s*\(\s*\)\s*/u', ' ', $clean);
    $clean = preg_replace('/\s{2,}/u', ' ', trim($clean));
    return ['text' => $clean, 'links' => $links];
}

// Split into bullet points (by sentence)
function toBullets(string $text): array {
    $sentences = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    return array_filter(array_map('trim', $sentences));
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
        .lg-profile{display:grid;grid-template-columns:180px 1fr;gap:18px;align-items:start;border-left:4px solid var(--party-accent, var(--glass-border));padding-left:16px;border-radius:0 var(--radius-sm) var(--radius-sm) 0}
        .lg-photo{width:160px;height:200px;object-fit:cover;border-radius:10px;background:var(--surface-2);border:1px solid var(--glass-border)}
        .lg-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:14px 0}
        .lg-box{padding:14px 16px}
        .lg-table-wrap{overflow:auto;border:1px solid var(--glass-border);border-radius:14px;background:var(--glass-bg)}
        .lg-table{width:100%;border-collapse:collapse}
        .lg-table th,.lg-table td{padding:10px 12px;border-bottom:1px solid var(--glass-border);text-align:left;font-size:.92rem;color:var(--text-secondary)}
        .lg-table th{font-weight:600;color:var(--text-primary);position:sticky;top:0;background:var(--surface-1)}
        .lg-media-bullets{list-style:disc;margin:8px 0 0 1.2em;padding:0}
        .lg-media-bullets li{margin-bottom:6px;color:var(--text-secondary);line-height:1.5}
        .lg-sources-wrap{margin-top:12px}
        .lg-sources-btn{font-size:.75rem;padding:6px 12px;border-radius:var(--radius-sm);background:var(--surface-2);color:var(--text-tertiary);border:1px solid var(--glass-border);cursor:pointer;transition:background .2s}
        .lg-sources-btn:hover{background:var(--accent-muted);color:var(--accent)}
        .lg-sources-list{display:none;margin-top:8px;padding:10px 14px;background:var(--surface-2);border-radius:var(--radius-sm);border:1px solid var(--glass-border)}
        .lg-sources-list.open{display:block}
        .lg-sources-list a{display:block;font-size:.8rem;color:var(--accent);text-decoration:none;margin-bottom:6px;word-break:break-all}
        .lg-sources-list a:hover{text-decoration:underline}
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
<?php $partyColor = PartyColors::getColor($mp['club'] ?? null); $partyName = PartyColors::getDisplayName($mp['club'] ?? null); ?>
<div class="lg-container" style="--party-accent: <?php echo htmlspecialchars($partyColor); ?>;">
    <div class="lg-section-tabs" style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
        <a href="index.php?section=" class="lg-btn lg-btn-secondary" style="text-decoration:none;">Všetky</a>
        <a href="index.php?section=nrsr" class="lg-btn lg-btn-secondary" style="text-decoration:none;">Nové zákony (NR SR)</a>
        <a href="parliament.php" class="lg-btn lg-btn-secondary" style="text-decoration:none;">Hlasovania NR SR</a>
        <a href="poslanci.php" class="lg-btn lg-btn-primary" style="text-decoration:none;">Poslanci</a>
        <a href="index.php?section=slovlex" class="lg-btn lg-btn-secondary" style="text-decoration:none;">Zbierka zákonov</a>
        <a href="global-chat-ui.php" class="lg-btn lg-btn-success" style="text-decoration:none;">Opýtať sa celej zbierky</a>
    </div>
    <a class="lg-back-link" href="poslanci.php">← Späť na zoznam poslancov</a>
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
            <p class="lg-body"><?php echo htmlspecialchars((string)($mp['title'] ?? '')); ?> | <span style="color:var(--party-accent);font-weight:600;"><?php echo htmlspecialchars($partyName); ?></span></p>
            <p class="lg-body">Kraj: <?php echo htmlspecialchars((string)($mp['district'] ?? '-')); ?></p>
            <p class="lg-info" style="margin-top:10px;"><?php echo htmlspecialchars((string)($mp['card_text'] ?? 'Karta poslanca sa vygeneruje po prvom spracovaní cron skriptom.')); ?></p>
        </div>
    </div>

    <?php
    $allMediaLinks = [];
    if (!empty($mediaProfile['last_month']) || !empty($mediaProfile['last_year']) || !empty($mediaProfile['famous_quote']) || !empty($mediaProfile['expertise_areas'])):
        $lastMonth = parseMediaText((string)($mediaProfile['last_month'] ?? ''));
        $lastYear = parseMediaText((string)($mediaProfile['last_year'] ?? ''));
        $famousQuote = parseMediaText((string)($mediaProfile['famous_quote'] ?? ''));
        $merged = array_merge($lastMonth['links'], $lastYear['links'], $famousQuote['links']);
        $seen = [];
        $allMediaLinks = [];
        foreach ($merged as $l) {
            $u = $l['url'] ?? '';
            if ($u !== '' && !isset($seen[$u])) {
                $seen[$u] = true;
                $allMediaLinks[] = $l;
            }
        }
    ?>
    <div class="lg-section">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:8px;">
            <h2 class="lg-section-title" style="margin:0;">Profil z médií (AI analýza)</h2>
            <?php if (!empty($allMediaLinks)): ?>
            <button type="button" class="lg-sources-btn" id="sourcesBtn" aria-expanded="false">Zdroje (<?php echo count($allMediaLinks); ?>)</button>
            <?php endif; ?>
        </div>
        <?php if (!empty($lastMonth['text'])): ?>
        <div class="lg-card lg-box" style="margin-bottom:12px;">
            <strong>Za posledný mesiac</strong>
            <?php $bullets = toBullets($lastMonth['text']); if (!empty($bullets)): ?>
            <ul class="lg-media-bullets">
                <?php foreach ($bullets as $s): if (trim($s) === '') continue; ?>
                <li><?php echo htmlspecialchars($s); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <p class="lg-body" style="margin-top:8px;"><?php echo htmlspecialchars($lastMonth['text']); ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($lastYear['text'])): ?>
        <div class="lg-card lg-box" style="margin-bottom:12px;">
            <strong>Za posledný rok</strong>
            <?php $bulletsY = toBullets($lastYear['text']); if (!empty($bulletsY)): ?>
            <ul class="lg-media-bullets">
                <?php foreach ($bulletsY as $s): if (trim($s) === '') continue; ?>
                <li><?php echo htmlspecialchars($s); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?>
            <p class="lg-body" style="margin-top:8px;"><?php echo htmlspecialchars($lastYear['text']); ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($mediaProfile['famous_quote'])): $q = trim($famousQuote['text']); if ($q !== ''): ?>
        <div class="lg-card lg-box" style="margin-bottom:12px;">
            <strong>Známy výrok</strong>
            <blockquote class="lg-body" style="margin-top:8px;font-style:italic;border-left:4px solid var(--accent);padding-left:14px;">
                „<?php echo htmlspecialchars($q); ?>“
            </blockquote>
        </div>
        <?php endif; endif; ?>
        <?php if (!empty($mediaProfile['expertise_areas'])): ?>
        <div class="lg-card lg-box" style="margin-bottom:12px;">
            <strong>Oblasti pôsobenia / odbornosť</strong>
            <div class="lg-law-tags" style="margin-top:10px;">
                <?php foreach ($mediaProfile['expertise_areas'] as $area): ?>
                    <span class="lg-law-tag" style="background-color:var(--accent-muted);color:var(--accent);"><?php echo htmlspecialchars($area); ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php if (!empty($allMediaLinks)): ?>
        <div class="lg-sources-wrap">
            <div class="lg-sources-list" id="sourcesList" role="region" aria-label="Zdroje">
                <?php foreach ($allMediaLinks as $link): ?>
                <a href="<?php echo htmlspecialchars($link['url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($link['label'] ?: $link['url']); ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <p class="lg-caption" style="margin:14px 0;">Profil z médií sa vygeneruje spustením <code>php bin/cron-mp-profiles.php</code>.</p>
    <?php endif; ?>

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
(function(){
    var btn = document.getElementById('sourcesBtn');
    var list = document.getElementById('sourcesList');
    if (btn && list) {
        btn.addEventListener('click', function() {
            list.classList.toggle('open');
            btn.setAttribute('aria-expanded', list.classList.contains('open'));
        });
    }
})();
</script>
</body>
</html>
