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
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Moja pamäť - Monitor zákona</title>
    <link rel="stylesheet" href="css/liquid-glass.css">
    <style>.lg-memory-law-item{border-left-color:var(--accent);}.lg-memory-chat-item{border-left-color:var(--success);}.lg-memory-chat-item .lg-law-title a{color:var(--success);}.lg-empty-message{font-size:1.1rem;margin-bottom:10px;}</style>
</head>
<body>
    <div class="lg-container">
        <div class="lg-header" style="justify-content:space-between;flex-wrap:wrap;gap:16px;">
            <h1 class="lg-title">Moja pamäť</h1>
            <a href="index.php" class="lg-back-link">← Späť na hlavnú stránku</a>
        </div>

        <?php if (!$isPaid): ?>
        <div class="lg-section" style="padding:30px 0;text-align:center;">
            <h2 class="lg-section-title">Platená verzia</h2>
            <p class="lg-body" style="margin-bottom:20px;">Ukladanie zákonov do Mojej pamäte a prehľad AI chatov sú súčasťou platenej verzie.</p>
            <p class="lg-body" style="margin-bottom:24px;">Získajte neobmedzenú konverzáciu so zákonmi, ukladanie do pamäte a neobmedzené sťahovanie PDF.</p>
            <a href="pricing.php" class="lg-btn lg-btn-primary">Upgradovať na platenú verziu</a>
        </div>
        <?php else: ?>
        <div class="lg-section">
            <h2 class="lg-section-title">Uložené zákony</h2>
            <?php if (empty($savedLaws)): ?>
                <div class="lg-empty">
                    <div class="lg-empty-message">Zatiaľ ste si neuložili žiadne zákony.</div>
                    <div>Prechádzajte <a href="index.php" class="lg-link">zoznam zákonov</a> a ukladajte si tie, ktoré vás zaujímajú.</div>
                </div>
            <?php else: ?>
                <?php foreach ($savedLaws as $law): ?>
                    <div class="lg-law-item lg-memory-law-item">
                        <div class="lg-law-title">
                            <a href="law.php?id=<?php echo htmlspecialchars($law['id']); ?>">
                                <?php echo htmlspecialchars($law['title']); ?>
                            </a>
                        </div>
                        <div class="lg-law-date">
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

        <div class="lg-section">
            <h2 class="lg-section-title">AI chaty s megatextami</h2>
            <?php if (empty($chats)): ?>
                <div class="lg-empty">
                    <div class="lg-empty-message">Zatiaľ ste neviedli žiadne AI chaty.</div>
                    <div>Otvorte si <a href="index.php" class="lg-link">zákon</a> a začnite konverzáciu s AI o jeho obsahu.</div>
                </div>
            <?php else: ?>
                <?php foreach ($chats as $chat): ?>
                    <div class="lg-law-item lg-memory-chat-item">
                        <div class="lg-law-title">
                            <a href="law.php?id=<?php echo htmlspecialchars($chat['law_id']); ?>">
                                <?php echo htmlspecialchars($chat['law_title']); ?>
                            </a>
                        </div>
                        <div class="lg-caption" style="margin-bottom:8px;">
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
                            <div class="lg-caption" style="font-style:italic;margin-top:8px;">
                                <?php echo htmlspecialchars($preview); ?><?php echo strlen($preview) >= 150 ? '...' : ''; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="lg-footer" style="margin-top: 40px;">
            <p>Automaticky monitorované z <a href="https://www.nrsr.sk/web/default.aspx?SectionId=184" target="_blank">NR SR</a></p>
            <p style="margin-top: 10px;">
                <a href="terms.php">Podmienky používania</a> | Autor: Matúš Kaník
            </p>
        </div>
    </div>
</body>
</html>
