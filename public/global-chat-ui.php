<?php

require_once __DIR__ . '/../vendor/autoload.php';
\App\Maintenance::check();

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

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!Security::checkRateLimit($ip, 60, 60)) {
    http_response_code(429);
    die("Príliš veľa požiadaviek. Skúste znova neskôr.");
}

$db = new Database(Config::get('DB_PATH', 'data/sentinel.db') ?: 'data/sentinel.db');
$auth = new Auth($db);

if (!$auth->isLoggedIn()) {
    header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$userId = $auth->getUserId();
$existingChat = $db->getGlobalChat($userId);
$initialMessages = $existingChat['messages'] ?? [];
$isPaid = $auth->isPaid();
$userMessageCount = 0;
foreach ($initialMessages as $m) {
    if (isset($m['role']) && $m['role'] === 'user') {
        $userMessageCount++;
    }
}
$chatLimitReached = !$isPaid && $userMessageCount >= 1;
$useWebSearch = filter_var(Config::get('GLOBAL_CHAT_WEB_SEARCH', 'true'), FILTER_VALIDATE_BOOLEAN);

?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Opýtať sa celej zbierky - Monitor zákona</title>
    <link rel="stylesheet" href="css/liquid-glass.css">
    <script>(function(){if(localStorage.getItem('darkMode')==='1')document.documentElement.classList.add('dark-mode');})();</script>
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
    <div class="lg-container lg-container-wide">
        <a href="index.php" class="lg-back-link">← Späť na zoznam</a>

        <h1 class="lg-title" style="margin-bottom:10px;font-size:1.75rem;">Opýtať sa celej zbierky</h1>
        <p class="lg-section-content" style="margin-bottom:20px;">
            Zadajte otázku voľným textom. Odpoveď bude založená na relevantných úryvkoch z viacerých zákonov (NR SR a Zbierka zákonov). Odpoveď uvádza zdroje – názov zákona a prípadne §.
        </p>

        <div class="lg-chat" style="margin-top:20px;padding-top:20px;border-top:1px solid var(--glass-border);">
            <div class="lg-section-title">Globálny chat (knihovník)</div>
            <?php if ($chatLimitReached): ?>
                <div class="lg-section-content">
                    <p style="margin-bottom:12px;">Na ďalšie otázky v globálnom chate aktivujte platenú verziu.</p>
                    <a href="pricing.php" class="lg-btn lg-btn-success" style="display:inline-block;text-decoration:none;">Upgradovať na platenú verziu</a>
                </div>
            <?php else: ?>
                <div class="lg-section-content">
                    <?php if (!$isPaid): ?>
                        <p class="lg-caption" style="margin-bottom:10px;">Bezplatní používatelia: 1 otázka v globálnom chate. Ďalšie po upgrade.</p>
                    <?php endif; ?>
                    <?php if ($useWebSearch): ?>
                        <p class="lg-caption" style="margin-bottom:10px;color:var(--text-tertiary);">Odpovede využívajú premýšľanie a vyhľadávanie na webe – môžu trvať 30–60 s.</p>
                    <?php endif; ?>
                    <textarea id="global-chat-question" placeholder="Napíšte otázku k slovenským zákonom (napr. Čo hovorí zákon o dovolenke?)..."></textarea>
                    <div class="lg-actions">
                        <button id="global-chat-submit" type="button" class="lg-btn lg-btn-primary">Opýtať sa</button>
                        <button id="global-chat-reset" class="lg-btn lg-btn-secondary" type="button">Vymazať konverzáciu</button>
                    </div>
                    <div id="global-chat-error" class="lg-error" style="display:none;margin-top:10px;"></div>
                    <div id="global-chat-thread" class="lg-chat-thread"></div>
                </div>
            <?php endif; ?>
        </div>

        <div class="lg-footer" style="margin-top: 40px;">
            <p><a href="index.php">Späť na zoznam zákonov</a></p>
            <p style="margin-top: 10px;">
                <a href="prompts.php">Použité prompty</a> | <a href="terms.php">Podmienky používania</a> | Autor: Matúš Kaník
            </p>
        </div>
    </div>
    <?php if (!$chatLimitReached): ?>
    <script>
        (function() {
            const chatSubmit = document.getElementById('global-chat-submit');
            const chatReset = document.getElementById('global-chat-reset');
            const chatQuestion = document.getElementById('global-chat-question');
            const chatThread = document.getElementById('global-chat-thread');
            const chatError = document.getElementById('global-chat-error');

            let history = <?php echo json_encode($initialMessages, JSON_UNESCAPED_UNICODE); ?>;

            const renderHistory = (h) => {
                chatThread.innerHTML = '';
                (h || []).forEach((msg) => {
                    const item = document.createElement('div');
                    item.className = 'lg-chat-message ' + (msg.role === 'user' ? 'user' : 'assistant');
                    const meta = document.createElement('div');
                    meta.className = 'lg-chat-meta';
                    meta.textContent = msg.role === 'user' ? 'Vy' : 'AI';
                    const content = document.createElement('div');
                    content.textContent = msg.content;
                    item.appendChild(meta);
                    item.appendChild(content);
                    if (msg.role === 'assistant' && Array.isArray(msg.citations) && msg.citations.length > 0) {
                        const citeWrap = document.createElement('div');
                        citeWrap.className = 'lg-chat-citations';
                        citeWrap.style.cssText = 'margin-top:8px;font-size:0.85em;opacity:0.9;';
                        const esc = (s) => String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
                        citeWrap.innerHTML = '<strong>Zdroje:</strong> ' + msg.citations.map(function(c) {
                            return '<a href="' + esc(c.url || '#') + '" target="_blank" rel="noopener noreferrer">' + esc(c.title || c.url || 'Odkaz') + '</a>';
                        }).join(', ');
                        item.appendChild(citeWrap);
                    }
                    if (msg.role === 'assistant' && msg.source === 'fallback') {
                        const fallbackNote = document.createElement('div');
                        fallbackNote.className = 'lg-chat-fallback-note';
                        fallbackNote.style.cssText = 'margin-top:6px;font-size:0.8em;color:var(--text-tertiary);font-style:italic;';
                        fallbackNote.textContent = 'Poznámka: Web search nebol dostupný, odpoveď z databázy.';
                        item.appendChild(fallbackNote);
                    }
                    chatThread.appendChild(item);
                });
            };

            renderHistory(history);

            chatReset.addEventListener('click', async () => {
                chatError.style.display = 'none';
                chatError.textContent = '';
                try {
                    const response = await fetch('global-chat.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json; charset=UTF-8' },
                        body: JSON.stringify({ action: 'clear' })
                    });
                    const data = await response.json();
                    if (response.ok && data.ok) {
                        history = [];
                        renderHistory(history);
                    } else {
                        chatError.textContent = data.error || 'Nepodarilo sa vymazať konverzáciu.';
                        chatError.style.display = 'block';
                    }
                } catch (err) {
                    chatError.textContent = err.message || 'Chyba.';
                    chatError.style.display = 'block';
                }
            });

            chatSubmit.addEventListener('click', async () => {
                const question = chatQuestion.value.trim();
                chatError.style.display = 'none';
                chatError.textContent = '';
                if (!question) {
                    chatError.textContent = 'Zadajte otázku.';
                    chatError.style.display = 'block';
                    return;
                }
                const historyForRequest = history.slice(-10);
                history = history.concat([{ role: 'user', content: question }]);
                renderHistory(history);
                chatQuestion.value = '';
                chatSubmit.disabled = true;
                chatSubmit.textContent = 'Spracovávam...';
                try {
                    const response = await fetch('global-chat.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json; charset=UTF-8' },
                        body: JSON.stringify({
                            question: question,
                            history: historyForRequest
                        })
                    });
                    const data = await response.json();
                    if (!response.ok) {
                        if (response.status === 403 && data.upgrade_redirect) {
                            window.location.href = data.upgrade_redirect;
                            return;
                        }
                        throw new Error(data.error || 'Neznáma chyba.');
                    }
                    const answer = data.answer || '';
                    const assistantMsg = { role: 'assistant', content: answer };
                    if (Array.isArray(data.citations) && data.citations.length > 0) {
                        assistantMsg.citations = data.citations;
                    }
                    if (data.source === 'fallback') {
                        assistantMsg.source = 'fallback';
                    }
                    history = history.concat([assistantMsg]);
                    renderHistory(history);
                } catch (err) {
                    history.pop();
                    renderHistory(history);
                    chatError.textContent = err.message || 'Chyba pri spracovaní otázky.';
                    chatError.style.display = 'block';
                } finally {
                    chatSubmit.disabled = false;
                    chatSubmit.textContent = 'Opýtať sa';
                }
            });
        })();
    </script>
    <?php endif; ?>
    <script>
    (function() {
        var KEY = 'darkMode';
        var el = document.documentElement;
        var sw = document.getElementById('darkModeSwitch');
        var tg = document.getElementById('darkModeToggle');
        function isOn() { return localStorage.getItem(KEY) === '1'; }
        function apply(on) {
            if (on) { el.classList.add('dark-mode'); if (sw) { sw.classList.add('on'); sw.setAttribute('aria-checked', 'true'); } }
            else { el.classList.remove('dark-mode'); if (sw) { sw.classList.remove('on'); sw.setAttribute('aria-checked', 'false'); } }
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
