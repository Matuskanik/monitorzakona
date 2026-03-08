<?php

// DigitalOcean/Heroku: session save path must be writable (ephemeral fs)
if (is_writable('/tmp')) {
    session_save_path('/tmp');
}

// Graceful error handling for production
set_exception_handler(function (\Throwable $e) {
    error_log('Monitor zákona 500: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="sk"><head><meta charset="UTF-8"><title>Chyba</title></head><body>';
    echo '<h1>Dočasná chyba servera</h1>';
    echo '<p>Skúste stránku <a href="index.html">obnoviť</a> alebo sa vráťte neskôr.</p>';
    echo '</body></html>';
    exit;
});

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    error_log('Monitor zákona: vendor/autoload.php not found. Run: composer install');
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="sk"><head><meta charset="UTF-8"><title>Chyba</title></head><body>';
    echo '<h1>Chýbajúce závislosti</h1><p>Aplikácia nebola správne zostavená. Skontrolujte build logy v DigitalOcean.</p>';
    echo '<p><a href="index.html">Otvoriť statickú verziu</a></p></body></html>';
    exit;
}

require_once $autoload;
\App\Maintenance::check();

use App\Config;
use App\Database;
use App\Auth;
use App\OpenAIClient;
use App\Security;

try {
    Config::load();
} catch (\Exception $e) {
    die("Configuration error: " . htmlspecialchars($e->getMessage()));
}

Security::setSecurityHeaders();

// Rate limiting
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!Security::checkRateLimit($ip, 60, 60)) {
    http_response_code(429);
    die("Príliš veľa požiadaviek. Skúste znova neskôr.");
}

$db = new Database(Config::get('DB_PATH', 'data/sentinel.db') ?: 'data/sentinel.db');
$auth = new Auth($db);

// Redirect search params to global search page
$searchQuery = Security::validateSearchQuery($_GET['search'] ?? $_GET['q'] ?? null);
if (!empty($searchQuery)) {
    header('Location: search.php?q=' . urlencode($searchQuery));
    exit;
}
// Section filter: nrsr | slovlex | (empty = all)
$section = $_GET['section'] ?? '';
$originFilter = null;
if ($section === 'nrsr') {
    $originFilter = 'nrsr';
} elseif ($section === 'slovlex') {
    $originFilter = 'slovlex_zz';
}
$laws = $db->getLatestLaws(50, $originFilter);

// Home = section empty → show period summaries (month, quarter, year) instead of laws
$showDigest = ($section === '');
$periodSummaries = $showDigest ? $db->getLatestPeriodSummaries() : null;

// Fallback: when DB is empty (e.g. on Digital Ocean), show laws from committed JSON (only when no section filter or nrsr)
if (empty($laws) && ($originFilter === null || $originFilter === 'nrsr')) {
    $indexPath = __DIR__ . '/data/index.json';
    if (is_readable($indexPath)) {
        $indexData = json_decode(file_get_contents($indexPath), true);
        if (is_array($indexData)) {
            foreach ($indexData as $item) {
                $laws[] = [
                    'id' => $item['master_id'] ?? $item['id'] ?? '',
                    'master_id' => $item['master_id'] ?? '',
                    'title' => $item['title'] ?? '',
                    'approval_date' => $item['approval_date'] ?? '',
                    'source_url' => $item['source_url'] ?? '',
                    'created_at' => $item['created_at'] ?? null,
                    'ai_summary' => isset($item['tags']) ? json_encode(['tags' => $item['tags']]) : null,
                ];
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Monitor zákona</title>
    <link rel="stylesheet" href="css/liquid-glass.css">
    <script>(function(){if(localStorage.getItem('darkMode')==='1')document.documentElement.classList.add('dark-mode');})();</script>
    <style>
        .lg-logo{max-width:600px;width:auto;height:auto;flex-shrink:0;}
        .lg-header{display:flex;align-items:center;gap:28px;margin-bottom:28px;flex-wrap:wrap;}
        .lg-tagline{flex:1;min-width:200px;}
        .lg-digest-citizens.digest-good{background:rgba(52,199,89,0.15)!important;border-left:4px solid var(--success);}
        .lg-digest-citizens.digest-bad{background:rgba(255,59,48,0.12)!important;border-left:4px solid var(--danger);}
        .lg-digest-citizens.digest-neutral{background:var(--accent-muted);}
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
    <div class="lg-container" style="position: relative;">
        <?php if ($auth->isLoggedIn()): ?>
            <div class="lg-user-header">
                <span class="lg-user-email"><?php echo htmlspecialchars($auth->getUserEmail()); ?></span>
                <a href="my-memory.php" class="lg-btn lg-btn-success">Moja pamäť</a>
                <a href="pricing.php" class="lg-btn lg-btn-success"><?php echo $auth->isPaid() ? 'Cenník' : 'Upgradovať'; ?></a>
                <a href="logout.php" class="lg-btn lg-btn-danger">Odhlásiť sa</a>
            </div>
        <?php else: ?>
            <div class="lg-user-header">
                <a href="login.php" class="lg-btn lg-btn-primary">Prihlásiť sa</a>
                <a href="register.php" class="lg-btn lg-btn-success">Registrovať sa</a>
            </div>
        <?php endif; ?>
        <div class="lg-header">
            <img src="logo.png" alt="Monitor zákona" class="lg-logo">
            <p class="lg-tagline lg-tagline">Sledujeme nové zákony, legislatívne zmeny a oficiálne dokumenty vlády – prehľadne, na jednom mieste.</p>
        </div>
        
        <div class="lg-search-container">
            <form method="GET" action="search.php" class="lg-search-box">
                <input 
                    type="text" 
                    name="q" 
                    class="lg-search-input" 
                    placeholder="Hľadať vo všetkých zákonoch a poslancoch (názov, tagy, meno, strana...)" 
                >
                <button type="submit" class="lg-btn lg-btn-primary">Hľadať</button>
            </form>
        </div>
        <div class="lg-section-tabs" style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
            <a href="index.php" class="lg-btn <?php echo $section === '' ? 'lg-btn-primary' : 'lg-btn-secondary'; ?>" style="text-decoration:none;">Všetky</a>
            <a href="index.php?section=nrsr" class="lg-btn <?php echo $section === 'nrsr' ? 'lg-btn-primary' : 'lg-btn-secondary'; ?>" style="text-decoration:none;">Nové zákony (NR SR)</a>
            <a href="parliament.php" class="lg-btn lg-btn-secondary" style="text-decoration:none;">Hlasovania NR SR</a>
            <a href="poslanci.php" class="lg-btn lg-btn-secondary" style="text-decoration:none;">Poslanci</a>
            <a href="index.php?section=slovlex" class="lg-btn <?php echo $section === 'slovlex' ? 'lg-btn-primary' : 'lg-btn-secondary'; ?>" style="text-decoration:none;">Zbierka zákonov</a>
            <a href="search.php" class="lg-btn lg-btn-secondary" style="text-decoration:none;">Vyhľadávanie</a>
            <a href="global-chat-ui.php" class="lg-btn lg-btn-success" style="text-decoration:none;">Opýtať sa celej zbierky</a>
        </div>
        
        <?php if ($showDigest): ?>
            <?php
            $hasAny = ($periodSummaries['month'] ?? null) || ($periodSummaries['quarter'] ?? null) || ($periodSummaries['year'] ?? null);
            $monthNames = [1=>'január',2=>'február',3=>'marec',4=>'apríl',5=>'máj',6=>'jún',7=>'júl',8=>'august',9=>'september',10=>'október',11=>'november',12=>'december'];
            ?>
            <?php if ($hasAny): ?>
                <div class="lg-digest-header" style="margin-bottom:24px;">
                    <h2 style="font-size:1.4rem;font-weight:600;color:var(--text-primary);">Zhrnutia zákonov a listín zo Zbierky zákonov</h2>
                    <p style="color:var(--text-tertiary);font-size:0.95rem;margin-top:4px;">Agregované AI zhrnutia spracovaných zákonov zo Slov-Lexu a NR SR</p>
                </div>
                <div class="lg-digest-panels" style="display:flex;flex-direction:column;gap:24px;">
                    <?php
                    $periodLabels = [
                        'month' => ['title' => 'Zhrnutie mesiaca', 'icon' => '📅'],
                        'quarter' => ['title' => 'Zhrnutie štvrť roka', 'icon' => '📆'],
                        'year' => ['title' => 'Zhrnutie roka', 'icon' => '🗓️'],
                    ];
                    foreach (['month', 'quarter', 'year'] as $key):
                        $p = $periodSummaries[$key] ?? null;
                        if (!$p) continue;
                        $label = $periodLabels[$key];
                        $periodTypeParam = $key;
                        $periodYearParam = (int) ($p['period_year'] ?? 0);
                        $periodValueParam = 0;
                        $periodLabel = '';
                        if ($key === 'month' && !empty($p['period_month']) && !empty($p['period_year'])) {
                            $periodValueParam = (int) $p['period_month'];
                            $periodLabel = ($monthNames[$p['period_month']] ?? '') . ' ' . $p['period_year'];
                        } elseif ($key === 'quarter' && !empty($p['period_quarter']) && !empty($p['period_year'])) {
                            $periodValueParam = (int) $p['period_quarter'];
                            $periodLabel = 'Q' . $p['period_quarter'] . ' ' . $p['period_year'];
                        } elseif ($key === 'year' && !empty($p['period_year'])) {
                            $periodLabel = (string) $p['period_year'];
                        }
                        $periodChatTarget = 'period-chat-ui.php?type=' . urlencode($periodTypeParam)
                            . '&year=' . $periodYearParam
                            . '&value=' . $periodValueParam;
                        $periodChatUrl = $auth->isLoggedIn()
                            ? $periodChatTarget
                            : ('login.php?redirect=' . urlencode($periodChatTarget));
                        $summary = $p['summary_paragraph'] ?? '';
                        $changes = $p['changes'] ?? '';
                        $affected = $p['affected_groups'] ?? [];
                        $positives = $p['positives'] ?? [];
                        $negatives = $p['negatives'] ?? [];
                        $lawsCount = $p['laws_count'] ?? 0;
                    ?>
                    <div class="lg-digest-panel" style="background:var(--glass-bg);backdrop-filter:blur(var(--glass-blur));border-radius:var(--radius-lg);padding:24px;border:1px solid var(--glass-border);box-shadow:var(--glass-shadow);">
                        <h3 style="font-size:1.2rem;font-weight:600;margin-bottom:12px;display:flex;align-items:center;gap:8px;">
                            <span><?php echo $label['icon']; ?></span>
                            <?php echo htmlspecialchars($label['title']); ?>
                            <?php if ($periodLabel): ?>
                            <span style="font-weight:500;color:var(--text-secondary);">(<?php echo htmlspecialchars($periodLabel); ?>)</span>
                            <?php endif; ?>
                            <?php if ($lawsCount > 0): ?>
                            <span style="font-size:0.85rem;color:var(--text-tertiary);">— <?php echo (int)$lawsCount; ?> zákonov</span>
                            <?php endif; ?>
                            <a href="<?php echo htmlspecialchars($periodChatUrl); ?>" class="lg-btn lg-btn-primary" style="margin-left:auto;text-decoration:none;">Opýtať sa AI</a>
                        </h3>
                        <?php if ($summary): ?>
                        <p style="margin-bottom:12px;line-height:1.6;"><strong>Stručné zhrnutie:</strong> <?php echo nl2br(htmlspecialchars($summary)); ?></p>
                        <?php endif; ?>
                        <?php if ($changes): ?>
                        <p style="margin-bottom:12px;line-height:1.6;"><strong>Aktuálne zmeny:</strong> <?php echo nl2br(htmlspecialchars($changes)); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($affected)): ?>
                        <div style="margin:12px 0;">
                            <strong style="color:var(--text-secondary);">Zasiahnuté skupiny:</strong>
                            <ul style="margin:6px 0 0 20px;padding:0;">
                                <?php foreach ($affected as $ag): ?>
                                <li style="margin-bottom:4px;"><?php echo htmlspecialchars(is_string($ag) ? $ag : ($ag['group'] ?? $ag['impact'] ?? json_encode($ag))); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($positives)): ?>
                        <div class="lg-digest-citizens digest-good" style="margin-top:12px;padding:12px;border-radius:var(--radius-sm);background:rgba(52,199,89,0.15);border-left:4px solid var(--success);">
                            <strong>Pozitívne:</strong>
                            <ul style="margin:6px 0 0 20px;padding:0;">
                                <?php foreach ($positives as $pos): ?>
                                <li style="margin-bottom:4px;"><?php echo htmlspecialchars(is_string($pos) ? $pos : ($pos['explanation'] ?? $pos['positive'] ?? json_encode($pos))); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($negatives)): ?>
                        <div class="lg-digest-citizens digest-bad" style="margin-top:12px;padding:12px;border-radius:var(--radius-sm);background:rgba(255,59,48,0.12);border-left:4px solid var(--danger);">
                            <strong>Negatívne:</strong>
                            <ul style="margin:6px 0 0 20px;padding:0;">
                                <?php foreach ($negatives as $neg): ?>
                                <li style="margin-bottom:4px;"><?php echo htmlspecialchars(is_string($neg) ? $neg : ($neg['explanation'] ?? $neg['negative'] ?? json_encode($neg))); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="lg-empty">
                    <p>Zhrnutia za obdobie sa pripravujú.</p>
                    <p style="margin-top: 10px; font-size: 0.9em;">Spustite najprv spracovanie zákonov: <code>php bin/crawl-slovlex.php 2</code> a <code>php bin/summarize-slovlex.php 0 2025</code>, <code>php bin/summarize-slovlex.php 0 2026</code>. Potom: <code>php bin/generate-period-summaries.php</code>.</p>
                </div>
            <?php endif; ?>
        <?php elseif (empty($laws)): ?>
            <div class="lg-empty">
                <?php if ($section === 'slovlex'): ?>
                    <p>Zatiaľ neboli importované žiadne zákony zo Zbierky zákonov.</p>
                    <p style="margin-top: 10px; font-size: 0.9em;">Spustite v priečinku projektu: <code>php bin/crawl-slovlex.php 2</code> (posledné 2 roky) alebo <code>php bin/crawl-slovlex.php 10</code> (posledných 10 rokov). Na predspracovanie AI zhrnutí za aktuálny rok: <code>php bin/process-year-slovlex.php</code>.</p>
                <?php elseif ($section === 'nrsr'): ?>
                    <p>Zatiaľ neboli spracované žiadne nové zákony (NR SR).</p>
                    <p style="margin-top: 10px; font-size: 0.9em;">Spustite <code>php bin/cron.php</code> na spracovanie.</p>
                <?php else: ?>
                    <p>Zatiaľ neboli spracované žiadne zákony.</p>
                    <p style="margin-top: 10px; font-size: 0.9em;">Nové zákony (NR SR): <code>php bin/cron.php</code>. Zbierka zákonov: <code>php bin/crawl-slovlex.php 2</code>.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($laws as $law): ?>
                <div class="lg-law-item">
                    <?php
                    $tags = [];
                    if (!empty($law['ai_summary'])) {
                        $summary = json_decode($law['ai_summary'], true);
                        if ($summary && isset($summary['tags']) && is_array($summary['tags'])) {
                            $tags = $summary['tags'];
                        }
                    }
                    if (!empty($tags)):
                    ?>
                    <div class="lg-law-tags">
                        <?php foreach ($tags as $tag): ?>
                            <?php $color = \App\OpenAIClient::getTagColor($tag); ?>
                            <span class="lg-law-tag" style="background-color: <?php echo htmlspecialchars($color); ?>;">
                                <?php echo htmlspecialchars($tag); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($law['human_title'])): ?>
                    <div class="lg-law-human-title" style="font-size:0.95rem;color:var(--text-secondary);margin-bottom:4px;"><?php echo htmlspecialchars($law['human_title']); ?></div>
                    <?php endif; ?>
                    <div class="lg-law-title">
                        <a href="law.php?id=<?php echo htmlspecialchars($law['id']); ?>">
                            <?php echo htmlspecialchars($law['title']); ?>
                        </a>
                    </div>
                    <div class="lg-law-date">
                        <?php if (!empty($law['approval_date'])): ?>
                            <span class="law-date-label">Zverejnené:</span> <?php echo htmlspecialchars($law['approval_date']); ?>
                        <?php else: ?>
                            <span class="law-date-label">Zverejnené:</span> dátum nie je dostupný
                        <?php endif; ?>
                        <?php if (!empty($law['created_at'])): ?>
                            <?php 
                            try {
                                $createdDate = new DateTime($law['created_at']);
                                $formattedDate = $createdDate->format('d.m.Y');
                            } catch (Exception $e) {
                                $formattedDate = substr($law['created_at'], 0, 10);
                            }
                            ?>
                            | <span class="law-date-label">Spracované:</span> <?php echo htmlspecialchars($formattedDate); ?>
                        <?php endif; ?>
                    </div>
                    <div class="lg-law-source">
                        <a href="<?php echo htmlspecialchars($law['source_url']); ?>" target="_blank">
                            Zdroj: <?php echo (isset($law['origin']) && $law['origin'] === 'slovlex_zz') ? 'Slov-Lex' : 'NR SR'; ?> →
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <div class="lg-footer">
            <p>Automaticky monitorované z <a href="https://www.nrsr.sk/web/default.aspx?SectionId=184" target="_blank">NR SR</a></p>
            <p style="margin-top: 10px;">
                <a href="prompts.php">Použité prompty</a> | <a href="terms.php">Podmienky používania</a> | Autor: Matúš Kaník
            </p>
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


