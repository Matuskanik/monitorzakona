<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;

Config::load();
$db = new Database(Config::get('DB_PATH', 'data/sentinel.db'));

$monthParam = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $monthParam)) {
    $monthParam = date('Y-m');
}

[$year, $month] = array_map('intval', explode('-', $monthParam));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 100;
$totalVotings = $db->countParliamentVotings();
$totalPages = max(1, (int)ceil($totalVotings / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;
$votings = $db->getParliamentVotingsPage($perPage, $offset);
$mps = $db->getTopParliamentMps(300);
$monthlyStats = $db->buildMonthlyVotingStats($year, $month);
$monthlyReport = $db->getMonthlyParliamentReport($year, $month);
$months = $db->getAvailableParliamentMonths(18);
?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Hlasovania NR SR</title>
    <link rel="stylesheet" href="css/liquid-glass.css">
    <script>(function(){if(localStorage.getItem('darkMode')==='1')document.documentElement.classList.add('dark-mode');})();</script>
    <style>
        .lg-title-row{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;flex-wrap:wrap;margin-bottom:18px}
        .lg-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-bottom:18px}
        .lg-kpi{padding:14px 16px}
        .lg-kpi-title{font-size:.82rem;color:var(--text-tertiary);text-transform:uppercase;letter-spacing:.04em}
        .lg-kpi-value{font-size:1.15rem;font-weight:700;color:var(--text-primary);margin-top:4px}
        .lg-table-wrap{overflow:auto;border:1px solid var(--glass-border);border-radius:14px;background:var(--glass-bg)}
        .lg-table{width:100%;border-collapse:collapse}
        .lg-table th,.lg-table td{padding:10px 12px;border-bottom:1px solid var(--glass-border);text-align:left;font-size:.92rem;color:var(--text-secondary)}
        .lg-table th{font-weight:600;color:var(--text-primary);position:sticky;top:0;background:var(--surface-1)}
        .lg-pill{display:inline-block;padding:3px 8px;border-radius:999px;font-size:.75rem;font-weight:700}
        .lg-pill-ok{background:rgba(52,199,89,.2);color:#1e7a37}
        .lg-pill-no{background:rgba(255,59,48,.2);color:#b3261e}
        .lg-pagination{margin-top:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
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
        <a href="parliament.php" class="lg-btn lg-btn-primary" style="text-decoration:none;">Hlasovania NR SR</a>
        <a href="index.php?section=slovlex" class="lg-btn lg-btn-secondary" style="text-decoration:none;">Zbierka zákonov</a>
        <a href="global-chat-ui.php" class="lg-btn lg-btn-success" style="text-decoration:none;">Opýtať sa celej zbierky</a>
    </div>

    <div class="lg-title-row">
        <h1 class="lg-title">Hlasovania NR SR a profily poslancov</h1>
        <span class="lg-caption">Volebné obdobie: IX.</span>
    </div>

    <div class="lg-cards">
        <div class="lg-card lg-kpi"><div class="lg-kpi-title">Mesiac</div><div class="lg-kpi-value"><?php echo htmlspecialchars(sprintf('%02d/%d', $month, $year)); ?></div></div>
        <div class="lg-card lg-kpi"><div class="lg-kpi-title">Historicky hlasovaní</div><div class="lg-kpi-value"><?php echo (int)$totalVotings; ?></div></div>
        <div class="lg-card lg-kpi"><div class="lg-kpi-title">Hlasovaní v mesiaci</div><div class="lg-kpi-value"><?php echo (int)$monthlyStats['total_votings']; ?></div></div>
        <div class="lg-card lg-kpi"><div class="lg-kpi-title">Schválené</div><div class="lg-kpi-value"><?php echo (int)$monthlyStats['approved']; ?></div></div>
        <div class="lg-card lg-kpi"><div class="lg-kpi-title">Neschválené</div><div class="lg-kpi-value"><?php echo (int)$monthlyStats['rejected']; ?></div></div>
        <div class="lg-card lg-kpi"><div class="lg-kpi-title">Priemerná prítomnosť</div><div class="lg-kpi-value"><?php echo number_format((float)$monthlyStats['avg_present'], 1, ',', ' '); ?></div></div>
    </div>

    <form method="GET" class="lg-search-container" style="margin-top:0;">
        <label for="month">Mesiac reportu: </label>
        <select name="month" id="month" class="lg-search-input" style="max-width:240px;">
            <?php foreach ($months as $m): ?>
                <?php $ym = $m['ym'] ?? ''; ?>
                <option value="<?php echo htmlspecialchars($ym); ?>" <?php echo $ym === $monthParam ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($ym); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="lg-btn lg-btn-primary">Zobraziť</button>
    </form>

    <div class="lg-info"><?php echo htmlspecialchars($monthlyReport['summary_text'] ?? 'Mesačný report zatiaľ nebol uložený. Spustite cron-parliament.php.'); ?></div>

    <div class="lg-section">
        <h2 class="lg-section-title">Historické hlasovania (volebné obdobie)</h2>
        <div class="lg-table-wrap">
        <table class="lg-table">
            <thead>
                <tr>
                    <th>Dátum</th>
                    <th>Číslo</th>
                    <th>Názov</th>
                    <th>Výsledok</th>
                    <th>Za/Proti</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($votings as $v): ?>
                <tr>
                    <td><?php echo htmlspecialchars(($v['voting_date'] ?? '-') . ' ' . ($v['voting_time'] ?? '')); ?></td>
                    <td><?php echo htmlspecialchars((string)($v['voting_number'] ?? '-')); ?></td>
                    <td><a href="parliament-voting.php?id=<?php echo (int)$v['id']; ?>"><?php echo htmlspecialchars($v['title']); ?></a></td>
                    <td>
                        <?php $ok = (($v['result'] ?? '') === 'schválené'); ?>
                        <span class="lg-pill <?php echo $ok ? 'lg-pill-ok' : 'lg-pill-no'; ?>">
                            <?php echo htmlspecialchars($v['result_text'] ?? $v['result'] ?? '-'); ?>
                        </span>
                    </td>
                    <td><?php echo (int)($v['votes_for'] ?? 0); ?> / <?php echo (int)($v['votes_against'] ?? 0); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="lg-pagination">
            <?php if ($page > 1): ?>
                <a class="lg-btn lg-btn-secondary" style="text-decoration:none;" href="parliament.php?month=<?php echo urlencode($monthParam); ?>&page=<?php echo $page - 1; ?>">← Predchádzajúca</a>
            <?php endif; ?>
            <span class="lg-caption">Strana <?php echo $page; ?> / <?php echo $totalPages; ?></span>
            <?php if ($page < $totalPages): ?>
                <a class="lg-btn lg-btn-secondary" style="text-decoration:none;" href="parliament.php?month=<?php echo urlencode($monthParam); ?>&page=<?php echo $page + 1; ?>">Ďalšia →</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="lg-section">
        <h2 class="lg-section-title">Profily poslancov</h2>
        <div class="lg-table-wrap">
        <table class="lg-table">
            <thead>
                <tr>
                    <th>Poslanec</th>
                    <th>Klub</th>
                    <th>Dochádzka %</th>
                    <th>Hlasovania</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($mps as $mp): ?>
                <tr>
                    <td><a href="parliament-mp.php?id=<?php echo (int)$mp['id']; ?>"><?php echo htmlspecialchars($mp['full_name']); ?></a></td>
                    <td><?php echo htmlspecialchars((string)($mp['club'] ?? '-')); ?></td>
                    <td><?php echo number_format((float)($mp['attendance_pct'] ?? 0), 1, ',', ' '); ?></td>
                    <td><?php echo (int)($mp['total_votes'] ?? 0); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="lg-footer">
        <p>Automaticky monitorované z <a href="https://www.nrsr.sk/web/default.aspx?sid=schodze/hlasovanie" target="_blank">NR SR hlasovania</a></p>
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
