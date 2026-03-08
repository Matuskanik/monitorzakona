<?php

require_once __DIR__ . '/../vendor/autoload.php';

\App\Maintenance::check();

use App\Config;
use App\Database;
use App\Auth;
use App\Security;
use App\OpenAIClient;
use App\PartyColors;

try {
    Config::load();
} catch (\Exception $e) {
    die("Configuration error: " . htmlspecialchars($e->getMessage()));
}

Security::setSecurityHeaders();

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!Security::checkRateLimit($ip, 60, 60)) {
    http_response_code(429);
    die("Príliš veľa požiadaviek. Skúste znova neskôr.");
}

$db = new Database(Config::get('DB_PATH', 'data/sentinel.db') ?: 'data/sentinel.db');
$auth = new Auth($db);

$searchQuery = Security::validateSearchQuery($_GET['q'] ?? $_GET['search'] ?? null);

$laws = [];
$mps = [];

if (!empty($searchQuery)) {
    $laws = $db->searchLawsGlobal($searchQuery, 30);
    $mps = $db->searchMpsGlobal($searchQuery, 30);
}

$totalResults = count($laws) + count($mps);

?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title><?php echo $searchQuery ? 'Vyhľadávanie: ' . htmlspecialchars($searchQuery) : 'Vyhľadávanie'; ?> - Monitor zákona</title>
    <link rel="stylesheet" href="css/liquid-glass.css">
    <script>(function(){if(localStorage.getItem('darkMode')==='1')document.documentElement.classList.add('dark-mode');})();</script>
    <style>
        .lg-logo{max-width:600px;width:auto;height:auto;flex-shrink:0;}
        .lg-search-hero{margin-bottom:28px;}
        .lg-search-result-law, .lg-search-result-mp{display:block;text-decoration:none;color:inherit;background:var(--glass-bg);border-radius:var(--radius-md);padding:16px;margin-bottom:12px;border:1px solid var(--glass-border);transition:box-shadow 0.2s;}
        .lg-search-result-law:hover, .lg-search-result-mp:hover{box-shadow:0 8px 24px rgba(0,0,0,0.12);}
        .lg-search-result-mp{border-left:4px solid var(--party-accent, var(--accent));}
        .lg-search-section{margin-bottom:32px;}
        .lg-search-section-title{font-size:1.2rem;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px;}
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

        <div class="lg-section-tabs" style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
            <a href="index.php" class="lg-btn lg-btn-secondary" style="text-decoration:none;">Všetky</a>
            <a href="index.php?section=nrsr" class="lg-btn lg-btn-secondary" style="text-decoration:none;">Nové zákony (NR SR)</a>
            <a href="parliament.php" class="lg-btn lg-btn-secondary" style="text-decoration:none;">Hlasovania NR SR</a>
            <a href="poslanci.php" class="lg-btn lg-btn-secondary" style="text-decoration:none;">Poslanci</a>
            <a href="index.php?section=slovlex" class="lg-btn lg-btn-secondary" style="text-decoration:none;">Zbierka zákonov</a>
            <a href="search.php" class="lg-btn lg-btn-primary" style="text-decoration:none;">Vyhľadávanie</a>
            <a href="global-chat-ui.php" class="lg-btn lg-btn-success" style="text-decoration:none;">Opýtať sa celej zbierky</a>
        </div>

        <div class="lg-search-hero">
            <h1 class="lg-title" style="margin-bottom:16px;">Vyhľadávanie</h1>
            <form method="GET" action="search.php" class="lg-search-box">
                <input type="text" name="q" class="lg-search-input" placeholder="Hľadať vo všetkých zákonoch a poslancoch (názov, tagy, meno, strana...)" value="<?php echo htmlspecialchars($searchQuery); ?>" autofocus>
                <button type="submit" class="lg-btn lg-btn-primary">Hľadať</button>
            </form>
        </div>

        <?php if (!empty($searchQuery)): ?>
            <div class="lg-search-results-info" style="margin-bottom:24px;color:var(--text-secondary);">
                Nájdených <?php echo $totalResults; ?> výsledkov pre "<?php echo htmlspecialchars($searchQuery); ?>"
            </div>

            <?php if ($totalResults === 0): ?>
                <div class="lg-empty">
                    <p>Pre vyhľadávanie "<?php echo htmlspecialchars($searchQuery); ?>" neboli nájdené žiadne výsledky.</p>
                    <p style="margin-top:10px;font-size:0.9em;">Skúste iný výraz alebo <a href="global-chat-ui.php" class="lg-link">opýtajte sa celej zbierky</a>.</p>
                </div>
            <?php else: ?>
                <?php if (!empty($laws)): ?>
                <div class="lg-search-section">
                    <h2 class="lg-search-section-title">📜 Zákony (<?php echo count($laws); ?>)</h2>
                    <?php foreach ($laws as $law): ?>
                        <?php
                        $tags = [];
                        if (!empty($law['ai_summary'])) {
                            $s = json_decode($law['ai_summary'], true);
                            if ($s && isset($s['tags']) && is_array($s['tags'])) {
                                $tags = $s['tags'];
                            }
                        }
                        ?>
                        <a href="law.php?id=<?php echo htmlspecialchars($law['id']); ?>" class="lg-search-result-law">
                            <?php if (!empty($law['human_title'])): ?>
                            <div style="font-size:0.95rem;color:var(--text-secondary);margin-bottom:4px;"><?php echo htmlspecialchars($law['human_title']); ?></div>
                            <?php endif; ?>
                            <div style="font-weight:600;"><?php echo htmlspecialchars($law['title']); ?></div>
                            <?php if (!empty($tags)): ?>
                            <div style="margin-top:8px;">
                                <?php foreach (array_slice($tags, 0, 5) as $tag): ?>
                                    <?php $color = OpenAIClient::getTagColor($tag); ?>
                                    <span class="lg-law-tag" style="background-color:<?php echo htmlspecialchars($color); ?>;font-size:0.8rem;padding:2px 8px;margin-right:4px;"><?php echo htmlspecialchars($tag); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($law['approval_date'])): ?>
                            <div style="font-size:0.85rem;color:var(--text-tertiary);margin-top:6px;"><?php echo htmlspecialchars($law['approval_date']); ?></div>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($mps)): ?>
                <div class="lg-search-section">
                    <h2 class="lg-search-section-title">👤 Poslanci (<?php echo count($mps); ?>)</h2>
                    <?php foreach ($mps as $mp): ?>
                        <?php
                        $partyColor = PartyColors::getColor($mp['club'] ?? null);
                        $partyName = PartyColors::getDisplayName($mp['club'] ?? null);
                        ?>
                        <a href="parliament-mp.php?id=<?php echo (int)$mp['id']; ?>" class="lg-search-result-mp" style="--party-accent: <?php echo htmlspecialchars($partyColor); ?>;">
                            <div style="display:flex;align-items:center;gap:16px;">
                                <?php if (!empty($mp['photo_url'])): ?>
                                <img src="<?php echo htmlspecialchars($mp['photo_url']); ?>" alt="" style="width:48px;height:48px;border-radius:var(--radius-sm);object-fit:cover;">
                                <?php else: ?>
                                <div style="width:48px;height:48px;border-radius:var(--radius-sm);background:var(--surface-2);display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:var(--text-tertiary);"><?php echo mb_substr($mp['full_name'] ?? '?', 0, 1); ?></div>
                                <?php endif; ?>
                                <div>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars($mp['full_name']); ?></div>
                                    <div style="font-size:0.9rem;color:<?php echo htmlspecialchars($partyColor); ?>;"><?php echo htmlspecialchars($partyName); ?></div>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        <?php else: ?>
            <div class="lg-empty">
                <p>Zadajte výraz do vyhľadávacieho poľa a stlačte Hľadať.</p>
                <p style="margin-top:10px;font-size:0.9em;">Vyhľadávanie prehľadáva zákony (názov, ľudský popis, tagy) a poslancov (meno, strana, klub, profil).</p>
            </div>
        <?php endif; ?>

        <div class="lg-footer">
            <p><a href="prompts.php">Použité prompty</a> | <a href="terms.php">Podmienky používania</a> | Autor: Matúš Kaník</p>
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
        function toggle() { var on = !isOn(); localStorage.setItem(KEY, on ? '1' : '0'); apply(on); }
        apply(isOn());
        if (sw) sw.addEventListener('click', toggle);
        if (tg) tg.addEventListener('click', function(e) { if (e.target !== sw) toggle(); });
    })();
    </script>
</body>
</html>
