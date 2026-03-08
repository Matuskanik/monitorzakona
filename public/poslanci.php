<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\PartyColors;

Config::load();
$db = new Database(Config::get('DB_PATH', 'data/sentinel.db'));

$mps = $db->getAllParliamentMpsForMosaic();
usort($mps, static function (array $a, array $b): int {
    $orderA = PartyColors::getOrder($a['club'] ?? null);
    $orderB = PartyColors::getOrder($b['club'] ?? null);
    if ($orderA !== $orderB) {
        return $orderA <=> $orderB;
    }
    return strcmp($a['full_name'] ?? '', $b['full_name'] ?? '');
});
?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Poslanci NR SR</title>
    <link rel="stylesheet" href="css/liquid-glass.css">
    <script>(function(){if(localStorage.getItem('darkMode')==='1')document.documentElement.classList.add('dark-mode');})();</script>
    <style>
        .lg-title-row{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:18px}
        .lg-mosaic{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px;margin:20px 0}
        .lg-mosaic-tile{
            display:block;
            text-decoration:none;
            color:inherit;
            background:var(--glass-bg);
            backdrop-filter:blur(var(--glass-blur)) saturate(var(--glass-saturate));
            -webkit-backdrop-filter:blur(var(--glass-blur)) saturate(var(--glass-saturate));
            border:1px solid var(--glass-border);
            border-left:4px solid var(--party-accent, var(--glass-border));
            border-radius:var(--radius-lg);
            padding:0;
            overflow:hidden;
            transition:transform 0.2s ease,box-shadow 0.2s ease,border-color 0.2s ease;
        }
        .lg-mosaic-tile:hover{
            transform:translateY(-4px);
            box-shadow:0 12px 32px rgba(0,0,0,0.15);
        }
        html.dark-mode .lg-mosaic-tile:hover{box-shadow:0 12px 32px rgba(0,0,0,0.4)}
        .lg-mosaic-party{font-weight:500;}
        .lg-mosaic-photo{
            width:100%;
            aspect-ratio:3/4;
            object-fit:cover;
            background:var(--surface-2);
        }
        .lg-mosaic-body{padding:12px 14px}
        .lg-mosaic-name{font-weight:600;font-size:0.95rem;color:var(--text-primary);margin-bottom:4px;line-height:1.3}
        .lg-mosaic-party{font-size:0.8rem;color:var(--text-tertiary);}
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
    <div class="lg-section-tabs" style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
        <a href="index.php?section=" class="lg-btn lg-btn-secondary" style="text-decoration:none;">Všetky</a>
        <a href="index.php?section=nrsr" class="lg-btn lg-btn-secondary" style="text-decoration:none;">Nové zákony (NR SR)</a>
        <a href="parliament.php" class="lg-btn lg-btn-secondary" style="text-decoration:none;">Hlasovania NR SR</a>
        <a href="poslanci.php" class="lg-btn lg-btn-primary" style="text-decoration:none;">Poslanci</a>
        <a href="index.php?section=slovlex" class="lg-btn lg-btn-secondary" style="text-decoration:none;">Zbierka zákonov</a>
        <a href="search.php" class="lg-btn lg-btn-secondary" style="text-decoration:none;">Vyhľadávanie</a>
        <a href="global-chat-ui.php" class="lg-btn lg-btn-success" style="text-decoration:none;">Opýtať sa celej zbierky</a>
    </div>

    <div class="lg-title-row">
        <h1 class="lg-title">Poslanci NR SR</h1>
        <span class="lg-caption">Volebné obdobie IX. · <?php echo count($mps); ?> poslancov</span>
    </div>

    <div class="lg-mosaic">
        <?php
        $lastParty = '';
        foreach ($mps as $mp):
            $partyKey = PartyColors::getPartyFromClub($mp['club'] ?? null) ?: '_';
            $partyColor = PartyColors::getColor($mp['club'] ?? null);
            $partyName = PartyColors::getDisplayName($mp['club'] ?? null);
            if ($partyKey !== $lastParty):
                $lastParty = $partyKey;
        ?>
        <div class="lg-mosaic-group" style="grid-column:1/-1;display:flex;align-items:center;gap:10px;margin:16px 0 8px;padding-bottom:6px;border-bottom:1px solid var(--glass-border);">
            <span class="lg-mosaic-group-dot" style="width:10px;height:10px;border-radius:50%;background:<?php echo htmlspecialchars($partyColor); ?>;"></span>
            <span class="lg-section-title" style="margin:0;font-size:1rem;"><?php echo htmlspecialchars($partyName); ?></span>
        </div>
        <?php endif; ?>
            <a href="parliament-mp.php?id=<?php echo (int)$mp['id']; ?>" class="lg-mosaic-tile" style="--party-accent: <?php echo htmlspecialchars($partyColor); ?>;">
                <?php if (!empty($mp['photo_url'])): ?>
                    <img class="lg-mosaic-photo" src="<?php echo htmlspecialchars($mp['photo_url']); ?>" alt="<?php echo htmlspecialchars($mp['full_name']); ?>">
                <?php else: ?>
                    <div class="lg-mosaic-photo" style="display:flex;align-items:center;justify-content:center;font-size:2.5rem;color:var(--text-tertiary);"><?php echo mb_substr($mp['full_name'] ?? '?', 0, 1); ?></div>
                <?php endif; ?>
                <div class="lg-mosaic-body">
                    <div class="lg-mosaic-name"><?php echo htmlspecialchars($mp['full_name']); ?></div>
                    <div class="lg-mosaic-party" style="color:<?php echo htmlspecialchars($partyColor); ?>;"><?php echo htmlspecialchars($partyName); ?></div>
                </div>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="lg-footer">
        <p>Dáta z <a href="https://www.nrsr.sk/web/default.aspx?sid=poslanci/zoznam_abc" target="_blank">NR SR</a>. Profily dopĺňané z médií.</p>
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
