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

$type = (string) ($_GET['type'] ?? '');
$year = (int) ($_GET['year'] ?? 0);
$value = (int) ($_GET['value'] ?? 0);

if (!in_array($type, ['month', 'quarter', 'year'], true) || $year < 2000 || $year > 2100) {
    http_response_code(400);
    die("Neplatné obdobie.");
}
if ($type === 'month' && ($value < 1 || $value > 12)) {
    http_response_code(400);
    die("Neplatný mesiac.");
}
if ($type === 'quarter' && ($value < 1 || $value > 4)) {
    http_response_code(400);
    die("Neplatný štvrťrok.");
}

$monthNames = [1=>'január',2=>'február',3=>'marec',4=>'apríl',5=>'máj',6=>'jún',7=>'júl',8=>'august',9=>'september',10=>'október',11=>'november',12=>'december'];
$periodLabel = $year . '';
if ($type === 'month') {
    $periodLabel = ($monthNames[$value] ?? 'mesiac') . ' ' . $year;
} elseif ($type === 'quarter') {
    $periodLabel = 'Q' . $value . ' ' . $year;
}

$laws = $db->getLawsForPeriod($type, $year, $value);
$lawsCount = count($laws);
$useWebSearch = filter_var(Config::get('GLOBAL_CHAT_WEB_SEARCH', 'true'), FILTER_VALIDATE_BOOLEAN);
$periodSummary = $db->getPeriodSummary($type, $year, $value);
?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Opýtať sa AI k obdobiu - Monitor zákona</title>
    <link rel="stylesheet" href="css/liquid-glass.css">
    <script>(function(){if(localStorage.getItem('darkMode')==='1')document.documentElement.classList.add('dark-mode');})();</script>
    <style>
        @keyframes period-chat-spin {
            to { transform: rotate(360deg); }
        }
        .period-chat-spinner {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,0.4);
            border-top-color: #fff;
            border-radius: 50%;
            animation: period-chat-spin 0.8s linear infinite;
            vertical-align: middle;
            margin-right: 8px;
        }
        .lg-btn-primary .period-chat-spinner { border-color: rgba(255,255,255,0.4); border-top-color: #fff; }
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
    <div class="lg-container lg-container-wide">
        <a href="index.php" class="lg-back-link">← Späť na úvod</a>

        <h1 class="lg-title" style="margin-bottom:10px;font-size:1.75rem;">Opýtať sa AI k obdobiu: <?php echo htmlspecialchars($periodLabel); ?></h1>
        <p class="lg-section-content" style="margin-bottom:20px;">
            AI analyzuje zákony a listiny patriace do tohto obdobia (<?php echo (int) $lawsCount; ?> dokumentov) a zároveň priamo vyhľadáva aj na webe. Každá otázka sa spracuje samostatne.
        </p>
        <?php if ($useWebSearch): ?>
            <p class="lg-caption" style="margin-bottom:10px;color:var(--text-tertiary);">Režim: interné zákonné podklady + priame webové vyhľadávanie.</p>
        <?php endif; ?>

        <div class="lg-chat" style="margin-top:20px;padding-top:20px;border-top:1px solid var(--glass-border);">
            <div class="lg-section-title">Chat k obdobiu</div>
            <div class="lg-section-content">
                <textarea id="period-chat-question" placeholder="Napíšte otázku (napr. Ktorá zmena má najväčší dopad na malé firmy?)..."></textarea>
                <div class="lg-actions">
                    <button id="period-chat-submit" type="button" class="lg-btn lg-btn-primary"><span id="period-chat-btn-text">Opýtať sa AI</span></button>
                    <button id="period-chat-clear" class="lg-btn lg-btn-secondary" type="button">Vymazať výstup</button>
                </div>
                <div id="period-chat-error" class="lg-error" style="display:none;margin-top:10px;"></div>
                <div id="period-chat-thread" class="lg-chat-thread" style="margin-top:20px;"></div>

                <?php if ($periodSummary): ?>
                <div class="lg-digest-panel" style="margin-top:24px;background:var(--glass-bg);backdrop-filter:blur(var(--glass-blur));border-radius:var(--radius-lg);padding:24px;border:1px solid var(--glass-border);box-shadow:var(--glass-shadow);">
                    <h3 style="font-size:1.1rem;font-weight:600;margin-bottom:12px;color:var(--text-primary);">Zhrnutie obdobia</h3>
                    <?php
                    $summary = $periodSummary['summary_paragraph'] ?? '';
                    $changes = $periodSummary['changes'] ?? '';
                    $affected = $periodSummary['affected_groups'] ?? [];
                    $positives = $periodSummary['positives'] ?? [];
                    $negatives = $periodSummary['negatives'] ?? [];
                    ?>
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
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
    (function() {
        const submitBtn = document.getElementById('period-chat-submit');
        const clearBtn = document.getElementById('period-chat-clear');
        const questionEl = document.getElementById('period-chat-question');
        const threadEl = document.getElementById('period-chat-thread');
        const errorEl = document.getElementById('period-chat-error');
        const periodType = <?php echo json_encode($type, JSON_UNESCAPED_UNICODE); ?>;
        const periodYear = <?php echo (int) $year; ?>;
        const periodValue = <?php echo (int) $value; ?>;
        let history = [];

        function escHtml(s) {
            return String(s || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function render() {
            threadEl.innerHTML = '';
            history.forEach((msg) => {
                const item = document.createElement('div');
                item.className = 'lg-chat-message ' + (msg.role === 'user' ? 'user' : 'assistant');
                const meta = document.createElement('div');
                meta.className = 'lg-chat-meta';
                meta.textContent = msg.role === 'user' ? 'Vy' : 'AI';
                const content = document.createElement('div');
                content.innerHTML = escHtml(msg.content).replace(/\n/g, '<br>');
                item.appendChild(meta);
                item.appendChild(content);

                if (msg.role === 'assistant' && Array.isArray(msg.citations) && msg.citations.length > 0) {
                    const citeWrap = document.createElement('div');
                    citeWrap.className = 'lg-chat-citations';
                    citeWrap.style.cssText = 'margin-top:8px;font-size:0.85em;opacity:0.9;';
                    citeWrap.innerHTML = '<strong>Zdroje:</strong> ' + msg.citations.map(function(c) {
                        return '<a href="' + escHtml(c.url || '#') + '" target="_blank" rel="noopener noreferrer">' + escHtml(c.title || c.url || 'Odkaz') + '</a>';
                    }).join(', ');
                    item.appendChild(citeWrap);
                }
                if (msg.role === 'assistant' && msg.source === 'law_only') {
                    const note = document.createElement('div');
                    note.style.cssText = 'margin-top:6px;font-size:0.8em;color:var(--text-tertiary);font-style:italic;';
                    note.textContent = 'Odpoveď bola nájdená priamo v zákonoch obdobia.';
                    item.appendChild(note);
                }
                if (msg.role === 'assistant' && msg.source === 'fallback') {
                    const note = document.createElement('div');
                    note.style.cssText = 'margin-top:6px;font-size:0.8em;color:var(--text-tertiary);font-style:italic;';
                    note.textContent = 'Web fallback nebol dostupný, odpoveď je zo zákonných zdrojov.';
                    item.appendChild(note);
                }
                threadEl.appendChild(item);
            });
        }

        clearBtn.addEventListener('click', function() {
            history = [];
            render();
            errorEl.style.display = 'none';
        });

        submitBtn.addEventListener('click', async function() {
            const question = questionEl.value.trim();
            errorEl.style.display = 'none';
            errorEl.textContent = '';
            if (!question) {
                errorEl.textContent = 'Zadajte otázku.';
                errorEl.style.display = 'block';
                return;
            }

            history = [{ role: 'user', content: question }];
            render();
            questionEl.value = '';
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="period-chat-spinner"></span> Spracovávam...';

            try {
                const response = await fetch('period-chat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json; charset=UTF-8' },
                    body: JSON.stringify({
                        type: periodType,
                        year: periodYear,
                        value: periodValue,
                        question: question
                    })
                });
                const data = await response.json();
                if (!response.ok) {
                    throw new Error(data.error || 'Neznáma chyba.');
                }
                const assistantMsg = { role: 'assistant', content: data.answer || '' };
                if (Array.isArray(data.citations) && data.citations.length > 0) {
                    assistantMsg.citations = data.citations;
                }
                if (data.source) {
                    assistantMsg.source = data.source;
                }
                history.push(assistantMsg);
                render();
            } catch (err) {
                history.pop();
                render();
                errorEl.textContent = err.message || 'Chyba pri spracovaní otázky.';
                errorEl.style.display = 'block';
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<span id="period-chat-btn-text">Opýtať sa AI</span>';
            }
        });
    })();
    </script>
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
