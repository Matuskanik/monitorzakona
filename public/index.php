<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\OpenAIClient;

try {
    Config::load();
} catch (\Exception $e) {
    die("Configuration error: " . htmlspecialchars($e->getMessage()));
}

$db = new Database(Config::get('DB_PATH'));
$laws = $db->getLatestLaws(50);

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
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="logo.png" alt="Monitor zákona" class="logo">
            <p class="tagline">Zrozumiteľné analýzy slovenských zákonov, ktoré vám pomôžu pochopiť, ako vás ovplyvnia a ako môžete na ne reagovať.</p>
        </div>
        
        <?php if (empty($laws)): ?>
            <div class="empty">
                <p>Zatiaľ neboli spracované žiadne zákony.</p>
                <p style="margin-top: 10px; font-size: 0.9em;">Spustite <code>php bin/cron.php</code> na spracovanie.</p>
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
                <a href="prompts.php">Použité prompty</a> | Autor: Matúš Kaník
            </p>
        </div>
    </div>
</body>
</html>


