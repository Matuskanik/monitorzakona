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
$laws = $db->getLatestLaws(50);

// Fallback: when DB is empty (e.g. on Digital Ocean), show laws from committed JSON
if (empty($laws)) {
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
    <script>(function(){if(localStorage.getItem('darkMode')==='1')document.documentElement.classList.add('dark-mode');})();</script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.6;
            color: #333;
            background: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header {
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 30px;
        }
        .logo {
            max-width: 600px;
            width: auto;
            height: auto;
            flex-shrink: 0;
        }
        .tagline {
            font-size: 1.2em;
            color: #444;
            font-style: italic;
            font-weight: 400;
            line-height: 1.7;
            flex: 1;
        }
        .search-container {
            margin: 30px 0;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }
        .search-box {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .search-input {
            flex: 1;
            padding: 12px 16px;
            font-size: 1em;
            border: 2px solid #ddd;
            border-radius: 6px;
            transition: border-color 0.3s;
        }
        .search-input:focus {
            outline: none;
            border-color: #3498db;
        }
        .search-button {
            padding: 12px 24px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 1em;
            cursor: pointer;
            transition: background 0.3s;
        }
        .search-button:hover {
            background: #2980b9;
        }
        .search-results-info {
            margin-top: 15px;
            color: #7f8c8d;
            font-size: 0.9em;
        }
        .law-item {
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #3498db;
            background: #f9f9f9;
            transition: transform 0.2s;
        }
        .law-item:hover {
            transform: translateX(5px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .law-title {
            font-size: 1.2em;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 8px;
        }
        .law-title a {
            color: #3498db;
            text-decoration: none;
        }
        .law-title a:hover {
            text-decoration: underline;
        }
        .law-tags {
            margin-bottom: 8px;
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }
        .law-tag {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.75em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: white;
        }
        .law-date {
            color: #7f8c8d;
            font-size: 0.9em;
            margin-bottom: 5px;
        }
        .law-date-label {
            font-weight: 600;
            color: #34495e;
        }
        .law-source {
            font-size: 0.85em;
            color: #95a5a6;
        }
        .law-source a {
            color: #7f8c8d;
            text-decoration: none;
        }
        .law-source a:hover {
            text-decoration: underline;
        }
        .empty {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            text-align: center;
            color: #95a5a6;
            font-size: 0.9em;
        }
        .user-header {
            position: absolute;
            top: 20px;
            right: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #34495e;
        }
        .user-email {
            font-weight: 500;
        }
        .my-memory-button {
            padding: 8px 16px;
            background: #27ae60;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 0.9em;
            text-decoration: none;
            display: inline-block;
            transition: background 0.3s;
        }
        .my-memory-button:hover {
            background: #229954;
        }
        .login-link {
            padding: 8px 16px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 0.9em;
            text-decoration: none;
            display: inline-block;
            transition: background 0.3s;
        }
        .login-link:hover {
            background: #2980b9;
        }
        .logout-link {
            padding: 8px 16px;
            background: #e74c3c;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 0.9em;
            text-decoration: none;
            display: inline-block;
            transition: background 0.3s;
        }
        .logout-link:hover {
            background: #c0392b;
        }

        /* Dark mode */
        html.dark-mode body { background: #111; color: #eee; }
        html.dark-mode .container { background: #1a1a1a; box-shadow: 0 2px 8px rgba(0,0,0,0.4); }
        html.dark-mode .tagline { color: #bbb; }
        html.dark-mode .search-container { background: #222; border-color: #333; }
        html.dark-mode .search-input { background: #222; border-color: #444; color: #eee; }
        html.dark-mode .search-input::placeholder { color: #888; }
        html.dark-mode .search-input:focus { border-color: #5dade2; }
        html.dark-mode .search-button { background: #2980b9; }
        html.dark-mode .search-button:hover { background: #3498db; }
        html.dark-mode .search-results-info { color: #aaa; }
        html.dark-mode .search-results-info a { color: #5dade2; }
        html.dark-mode .law-item { background: #222; border-left-color: #3498db; }
        html.dark-mode .law-item:hover { box-shadow: 0 2px 8px rgba(255,255,255,0.05); }
        html.dark-mode .law-title { color: #e0e0e0; }
        html.dark-mode .law-title a { color: #5dade2; }
        html.dark-mode .law-date, html.dark-mode .law-date-label { color: #aaa; }
        html.dark-mode .law-source { color: #888; }
        html.dark-mode .law-source a { color: #aaa; }
        html.dark-mode .empty { color: #aaa; }
        html.dark-mode .empty a { color: #5dade2; }
        html.dark-mode .footer { border-top-color: #333; color: #888; }
        html.dark-mode .footer a { color: #aaa; }
        html.dark-mode .user-info, html.dark-mode .user-email { color: #ccc; }
        html.dark-mode .my-memory-button { background: #229954; }
        html.dark-mode .my-memory-button:hover { background: #27ae60; }
        html.dark-mode .login-link { background: #2980b9; }
        html.dark-mode .login-link:hover { background: #3498db; }
        html.dark-mode .logout-link { background: #c0392b; }
        html.dark-mode .logout-link:hover { background: #e74c3c; }

        /* Dark mode toggle – horný ľavý roh */
        .dark-mode-toggle {
            position: fixed;
            top: 16px;
            left: 16px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,0.95);
            padding: 8px 12px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            font-size: 0.85em;
            font-weight: 600;
        }
        html.dark-mode .dark-mode-toggle {
            background: rgba(30,30,30,0.95);
            box-shadow: 0 2px 8px rgba(0,0,0,0.4);
        }
        .dark-mode-toggle-row { display: flex; align-items: center; gap: 10px; }
        .dark-mode-toggle span { color: #333; }
        html.dark-mode .dark-mode-toggle span { color: #ddd; }
        .dark-mode-label {
            font-size: 0.7em;
            font-weight: 500;
            line-height: 1;
            max-width: 100%;
            text-align: center;
            color: #555;
        }
        html.dark-mode .dark-mode-label { color: #aaa; }
        .dark-mode-switch {
            position: relative;
            width: 52px;
            height: 26px;
            background: #ccc;
            border-radius: 13px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .dark-mode-switch.on { background: #27ae60; }
        html.dark-mode .dark-mode-switch { background: #444; }
        html.dark-mode .dark-mode-switch.on { background: #27ae60; }
        .dark-mode-switch::after {
            content: '';
            position: absolute;
            top: 2px;
            left: 2px;
            width: 22px;
            height: 22px;
            background: white;
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0,0,0,0.3);
            transition: transform 0.2s;
        }
        .dark-mode-switch.on::after { transform: translateX(26px); }
        .dark-mode-toggle .label-off { margin-right: 2px; }
        .dark-mode-toggle .label-on { margin-left: 2px; }
    </style>
</head>
<body>
    <div class="dark-mode-toggle" id="darkModeToggle" title="Tmavý režim">
        <div class="dark-mode-toggle-row">
            <span class="label-off">OFF</span>
            <div class="dark-mode-switch" id="darkModeSwitch" role="switch" aria-checked="false" aria-label="Tmavý režim"></div>
            <span class="label-on">ON</span>
        </div>
        <span class="dark-mode-label">Tmavý režim</span>
    </div>
    <div class="container" style="position: relative;">
        <?php if ($auth->isLoggedIn()): ?>
            <div class="user-header">
                <div class="user-info">
                    <span class="user-email"><?php echo htmlspecialchars($auth->getUserEmail()); ?></span>
                </div>
                <a href="my-memory.php" class="my-memory-button">Moja pamäť</a>
                <a href="pricing.php" class="login-link" style="background: #27ae60;"><?php echo $auth->isPaid() ? 'Cenník' : 'Upgradovať'; ?></a>
                <a href="logout.php" class="logout-link">Odhlásiť sa</a>
            </div>
        <?php else: ?>
            <div class="user-header">
                <a href="login.php" class="login-link">Prihlásiť sa</a>
                <a href="register.php" class="login-link" style="background: #27ae60;">Registrovať sa</a>
            </div>
        <?php endif; ?>
        <div class="header">
            <img src="logo.png" alt="Monitor zákona" class="logo">
            <p class="tagline">Zrozumiteľné analýzy slovenských zákonov, ktoré vám pomôžu pochopiť, ako vás ovplyvnia a ako môžete na ne reagovať.</p>
        </div>
        
        <div class="search-container">
            <form method="GET" action="" class="search-box">
                <input 
                    type="text" 
                    name="search" 
                    class="search-input" 
                    placeholder="Hľadať zákony podľa názvu alebo tagov (napr. financie, školstvo, dane...)" 
                    value="<?php echo htmlspecialchars($searchQuery); ?>"
                >
                <button type="submit" class="search-button">Hľadať</button>
            </form>
            <?php if (!empty($searchQuery)): ?>
                <div class="search-results-info">
                    Nájdených: <?php echo count($laws); ?> zákon<?php echo count($laws) === 1 ? '' : (count($laws) >= 2 && count($laws) <= 4 ? 'y' : 'ov'); ?> 
                    pre "<?php echo htmlspecialchars($searchQuery); ?>"
                    <a href="index.php" style="margin-left: 10px; color: #3498db;">Zrušiť vyhľadávanie</a>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if (empty($laws)): ?>
            <div class="empty">
                <?php if (!empty($searchQuery)): ?>
                    <p>Pre vyhľadávanie "<?php echo htmlspecialchars($searchQuery); ?>" neboli nájdené žiadne zákony.</p>
                    <p style="margin-top: 10px; font-size: 0.9em;">
                        <a href="index.php" style="color: #3498db;">Zobraziť všetky zákony</a>
                    </p>
                <?php else: ?>
                    <p>Zatiaľ neboli spracované žiadne zákony.</p>
                    <p style="margin-top: 10px; font-size: 0.9em;">Spustite <code>php bin/cron.php</code> na spracovanie.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($laws as $law): ?>
                <div class="law-item">
                    <?php
                    // Parse tags from AI summary
                    $tags = [];
                    if (!empty($law['ai_summary'])) {
                        $summary = json_decode($law['ai_summary'], true);
                        if ($summary && isset($summary['tags']) && is_array($summary['tags'])) {
                            $tags = $summary['tags'];
                        }
                    }
                    if (!empty($tags)):
                    ?>
                    <div class="law-tags">
                        <?php foreach ($tags as $tag): ?>
                            <?php 
                            $color = \App\OpenAIClient::getTagColor($tag);
                            ?>
                            <span class="law-tag" style="background-color: <?php echo htmlspecialchars($color); ?>;">
                                <?php echo htmlspecialchars($tag); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="law-title">
                        <a href="law.php?id=<?php echo htmlspecialchars($law['id']); ?>">
                            <?php echo htmlspecialchars($law['title']); ?>
                        </a>
                    </div>
                    <div class="law-date">
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
                                $formattedDate = substr($law['created_at'], 0, 10); // Fallback to first 10 chars
                            }
                            ?>
                            | <span class="law-date-label">Spracované:</span> <?php echo htmlspecialchars($formattedDate); ?>
                        <?php endif; ?>
                    </div>
                    <div class="law-source">
                        <a href="<?php echo htmlspecialchars($law['source_url']); ?>" target="_blank">
                            Zdroj: NR SR →
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        
        <div class="footer">
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


