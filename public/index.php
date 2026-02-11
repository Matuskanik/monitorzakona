<?php

require_once __DIR__ . '/../vendor/autoload.php';

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

// Handle search - validate and sanitize input
$searchQuery = Security::validateSearchQuery($_GET['search'] ?? null);
// Section filter: nrsr | slovlex | (empty = all)
$section = $_GET['section'] ?? '';
$originFilter = null;
if ($section === 'nrsr') {
    $originFilter = 'nrsr';
} elseif ($section === 'slovlex') {
    $originFilter = 'slovlex_zz';
}
$laws = $db->getLatestLaws(50, $originFilter);

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

// Filter laws if search query is provided
if (!empty($searchQuery)) {
    $searchLower = mb_strtolower($searchQuery, 'UTF-8');
    $filteredLaws = [];
    
    foreach ($laws as $law) {
        $titleLower = mb_strtolower($law['title'], 'UTF-8');
        $match = false;
        
        // Check if search matches title
        if (strpos($titleLower, $searchLower) !== false) {
            $match = true;
        }
        
        // Check if search matches tags
        if (!$match && !empty($law['ai_summary'])) {
            $summary = json_decode($law['ai_summary'], true);
            if ($summary && isset($summary['tags']) && is_array($summary['tags'])) {
                foreach ($summary['tags'] as $tag) {
                    $tagLower = mb_strtolower($tag, 'UTF-8');
                    if (strpos($tagLower, $searchLower) !== false) {
                        $match = true;
                        break;
                    }
                }
            }
        }
        
        if ($match) {
            $filteredLaws[] = $law;
        }
    }
    
    $laws = $filteredLaws;
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
    <style>.lg-logo{max-width:600px;width:auto;height:auto;flex-shrink:0;}.lg-header{display:flex;align-items:center;gap:28px;margin-bottom:28px;flex-wrap:wrap;}.lg-tagline{flex:1;min-width:200px;}</style>
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
            <p class="lg-tagline lg-tagline">Zrozumiteľné analýzy slovenských zákonov, ktoré vám pomôžu pochopiť, ako vás ovplyvnia a ako môžete na ne reagovať.</p>
        </div>
        
        <div class="lg-search-container">
            <form method="GET" action="" class="lg-search-box">
                <?php if ($section !== ''): ?>
                <input type="hidden" name="section" value="<?php echo htmlspecialchars($section); ?>">
                <?php endif; ?>
                <input 
                    type="text" 
                    name="search" 
                    class="lg-search-input" 
                    placeholder="Hľadať zákony podľa názvu alebo tagov (napr. financie, školstvo, dane...)" 
                    value="<?php echo htmlspecialchars($searchQuery); ?>"
                >
                <button type="submit" class="lg-btn lg-btn-primary">Hľadať</button>
            </form>
        </div>
        <div class="lg-section-tabs" style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
            <a href="index.php?<?php echo $searchQuery !== '' ? 'search=' . urlencode($searchQuery) . '&' : ''; ?>section=" class="lg-btn <?php echo $section === '' ? 'lg-btn-primary' : 'lg-btn-secondary'; ?>" style="text-decoration:none;">Všetky</a>
            <a href="index.php?<?php echo $searchQuery !== '' ? 'search=' . urlencode($searchQuery) . '&' : ''; ?>section=nrsr" class="lg-btn <?php echo $section === 'nrsr' ? 'lg-btn-primary' : 'lg-btn-secondary'; ?>" style="text-decoration:none;">Nové zákony (NR SR)</a>
            <a href="index.php?<?php echo $searchQuery !== '' ? 'search=' . urlencode($searchQuery) . '&' : ''; ?>section=slovlex" class="lg-btn <?php echo $section === 'slovlex' ? 'lg-btn-primary' : 'lg-btn-secondary'; ?>" style="text-decoration:none;">Zbierka zákonov</a>
            <a href="global-chat-ui.php" class="lg-btn lg-btn-success" style="text-decoration:none;">Opýtať sa celej zbierky</a>
        </div>
            <?php if (!empty($searchQuery)): ?>
                <div class="lg-search-results-info">
                    Nájdených: <?php echo count($laws); ?> zákon<?php echo count($laws) === 1 ? '' : (count($laws) >= 2 && count($laws) <= 4 ? 'y' : 'ov'); ?> 
                    pre "<?php echo htmlspecialchars($searchQuery); ?>"
                    <a href="index.php" class="lg-link" style="margin-left: 10px;">Zrušiť vyhľadávanie</a>
                </div>
            <?php endif; ?>
        
        <?php if (empty($laws)): ?>
            <div class="lg-empty">
                <?php if (!empty($searchQuery)): ?>
                    <p>Pre vyhľadávanie "<?php echo htmlspecialchars($searchQuery); ?>" neboli nájdené žiadne zákony.</p>
                    <p style="margin-top: 10px; font-size: 0.9em;">
                        <a href="index.php" class="lg-link">Zobraziť všetky zákony</a>
                    </p>
                <?php elseif ($section === 'slovlex'): ?>
                    <p>Zatiaľ neboli importované žiadne zákony zo Zbierky zákonov.</p>
                    <p style="margin-top: 10px; font-size: 0.9em;">Spustite v priečinku projektu: <code>php bin/crawl-slovlex.php 2</code> (posledné 2 roky) alebo <code>php bin/crawl-slovlex.php 10</code> (posledných 10 rokov).</p>
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


