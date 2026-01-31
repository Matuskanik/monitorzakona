<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\Auth;
use App\Security;

try {
    Config::load();
} catch (\Exception $e) {
    die("Configuration error: " . htmlspecialchars($e->getMessage()));
}

Security::setSecurityHeaders();

$db = new Database(Config::get('DB_PATH', 'data/sentinel.db') ?: 'data/sentinel.db');
$auth = new Auth($db);

// Require login
$auth->requireLogin();

$userId = $auth->getUserId();
$isPaid = $auth->isPaid();
$savedLaws = $isPaid ? $db->getUserSavedLaws($userId) : [];
$chats = $isPaid ? $db->getUserChats($userId) : [];

?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Moja pamäť - Monitor zákona</title>
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
            justify-content: space-between;
        }
        h1 {
            color: #2c3e50;
        }
        .back-link {
            color: #3498db;
            text-decoration: none;
            font-size: 0.9em;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .section {
            margin-bottom: 40px;
        }
        .section-title {
            font-size: 1.5em;
            color: #34495e;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #3498db;
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
        .law-date {
            color: #7f8c8d;
            font-size: 0.9em;
            margin-bottom: 5px;
        }
        .law-date-label {
            font-weight: 600;
            color: #34495e;
        }
        .chat-item {
            padding: 20px;
            margin-bottom: 15px;
            border-left: 4px solid #27ae60;
            background: #f9f9f9;
            transition: transform 0.2s;
        }
        .chat-item:hover {
            transform: translateX(5px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .chat-title {
            font-size: 1.2em;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 8px;
        }
        .chat-title a {
            color: #27ae60;
            text-decoration: none;
        }
        .chat-title a:hover {
            text-decoration: underline;
        }
        .chat-info {
            color: #7f8c8d;
            font-size: 0.9em;
            margin-bottom: 8px;
        }
        .chat-preview {
            color: #555;
            font-size: 0.9em;
            font-style: italic;
            margin-top: 8px;
        }
        .empty {
            text-align: center;
            padding: 40px;
            color: #7f8c8d;
        }
        .empty-message {
            font-size: 1.1em;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Moja pamäť</h1>
            <a href="index.php" class="back-link">← Späť na hlavnú stránku</a>
        </div>

        <?php if (!$isPaid): ?>
        <div class="section" style="padding:30px 0; text-align:center;">
            <h2 class="section-title">Platená verzia</h2>
            <p style="margin-bottom:20px; color:#555;">Ukladanie zákonov do Mojej pamäte a prehľad AI chatov sú súčasťou platenej verzie.</p>
            <p style="margin-bottom:24px;">Získajte neobmedzenú konverzáciu so zákonmi, ukladanie do pamäte a neobmedzené sťahovanie PDF.</p>
            <a href="pricing.php" class="back-link" style="display:inline-block; padding:12px 24px; background:#3498db; color:white; border-radius:8px; font-weight:bold;">Upgradovať na platenú verziu</a>
        </div>
        <?php else: ?>
        <div class="section">
            <h2 class="section-title">Uložené zákony</h2>
            <?php if (empty($savedLaws)): ?>
                <div class="empty">
                    <div class="empty-message">Zatiaľ ste si neuložili žiadne zákony.</div>
                    <div>Prechádzajte <a href="index.php" style="color: #3498db;">zoznam zákonov</a> a ukladajte si tie, ktoré vás zaujímajú.</div>
                </div>
            <?php else: ?>
                <?php foreach ($savedLaws as $law): ?>
                    <div class="law-item">
                        <div class="law-title">
                            <a href="law.php?id=<?php echo htmlspecialchars($law['id']); ?>">
                                <?php echo htmlspecialchars($law['title']); ?>
                            </a>
                        </div>
                        <div class="law-date">
                            <?php if (!empty($law['approval_date'])): ?>
                                <span class="law-date-label">Zverejnené:</span> <?php echo htmlspecialchars($law['approval_date']); ?>
                            <?php endif; ?>
                            <?php if (!empty($law['saved_at'])): ?>
                                | <span class="law-date-label">Uložené:</span> 
                                <?php 
                                try {
                                    $savedDate = new DateTime($law['saved_at']);
                                    echo htmlspecialchars($savedDate->format('d.m.Y H:i'));
                                } catch (Exception $e) {
                                    echo htmlspecialchars(substr($law['saved_at'], 0, 16));
                                }
                                ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="section">
            <h2 class="section-title">AI chaty s megatextami</h2>
            <?php if (empty($chats)): ?>
                <div class="empty">
                    <div class="empty-message">Zatiaľ ste neviedli žiadne AI chaty.</div>
                    <div>Otvorte si <a href="index.php" style="color: #3498db;">zákon</a> a začnite konverzáciu s AI o jeho obsahu.</div>
                </div>
            <?php else: ?>
                <?php foreach ($chats as $chat): ?>
                    <div class="chat-item">
                        <div class="chat-title">
                            <a href="law.php?id=<?php echo htmlspecialchars($chat['law_id']); ?>">
                                <?php echo htmlspecialchars($chat['law_title']); ?>
                            </a>
                        </div>
                        <div class="chat-info">
                            <?php if (!empty($chat['updated_at'])): ?>
                                <span class="law-date-label">Posledná aktualizácia:</span> 
                                <?php 
                                try {
                                    $updatedDate = new DateTime($chat['updated_at']);
                                    echo htmlspecialchars($updatedDate->format('d.m.Y H:i'));
                                } catch (Exception $e) {
                                    echo htmlspecialchars(substr($chat['updated_at'], 0, 16));
                                }
                                ?>
                            <?php endif; ?>
                            <?php if (!empty($chat['messages'])): ?>
                                | <span class="law-date-label">Správ:</span> <?php echo count($chat['messages']); ?>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($chat['messages'])): ?>
                            <?php 
                            $lastMessage = end($chat['messages']);
                            $preview = is_string($lastMessage) ? substr($lastMessage, 0, 150) : (isset($lastMessage['content']) ? substr($lastMessage['content'], 0, 150) : '');
                            ?>
                            <div class="chat-preview">
                                <?php echo htmlspecialchars($preview); ?><?php echo strlen($preview) >= 150 ? '...' : ''; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="footer" style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ecf0f1; text-align: center; color: #95a5a6; font-size: 0.9em;">
            <p>Automaticky monitorované z <a href="https://www.nrsr.sk/web/default.aspx?SectionId=184" target="_blank" style="color: #3498db; text-decoration: none;">NR SR</a></p>
            <p style="margin-top: 10px;">
                <a href="terms.php" style="color: #3498db; text-decoration: none;">Podmienky používania</a> | Autor: Matúš Kaník
            </p>
        </div>
    </div>
</body>
</html>
