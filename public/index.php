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

$db = new Database(Config::get('DB_PATH'));
$auth = new Auth($db);

// Handle search - validate and sanitize input
$searchQuery = Security::validateSearchQuery($_GET['search'] ?? null);
$laws = $db->getLatestLaws(50);

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
    <title>Monitor zákona</title>
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
    </style>
</head>
<body>
    <div class="container" style="position: relative;">
        <?php if ($auth->isLoggedIn()): ?>
            <div class="user-header">
                <div class="user-info">
                    <span class="user-email"><?php echo htmlspecialchars($auth->getUserEmail()); ?></span>
                </div>
                <a href="my-memory.php" class="my-memory-button">Moja pamäť</a>
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
</body>
</html>


