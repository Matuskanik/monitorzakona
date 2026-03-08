<?php

require_once __DIR__ . '/../vendor/autoload.php';
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

// Validate and sanitize input (id can be DB id or master_id when from JSON fallback)
$lawId = Security::validateIntegerId($_GET['id'] ?? null);
if ($lawId === null) {
    header('Location: index.php');
    exit;
}

$db = new Database(Config::get('DB_PATH', 'data/sentinel.db') ?: 'data/sentinel.db');
$auth = new Auth($db);

// Handle save/unsave action (only when law is from DB)
$law = $db->getPdo()->prepare("SELECT * FROM laws WHERE id = ?");
$law->execute([$lawId]);
$law = $law->fetch();

$fromJson = false;
if (!$law) {
    // Fallback: load from JSON (e.g. on Digital Ocean when DB is empty)
    $jsonPath = __DIR__ . '/data/laws/' . $lawId . '.json';
    if (is_readable($jsonPath)) {
        $json = json_decode(file_get_contents($jsonPath), true);
        if (is_array($json)) {
            $law = [
                'id' => $json['master_id'] ?? $lawId,
                'master_id' => $json['master_id'] ?? $lawId,
                'title' => $json['title'] ?? '',
                'human_title' => $json['human_title'] ?? null,
                'approval_date' => $json['approval_date'] ?? null,
                'source_url' => $json['source_url'] ?? '',
                'created_at' => $json['created_at'] ?? null,
                'updated_at' => $json['updated_at'] ?? null,
                'ai_summary' => isset($json['summary']) ? json_encode($json['summary']) : null,
                'processing_status' => 'completed',
                'text_extracted' => 1,
            ];
            $fromJson = true;
        }
    }
}

if (!$law) {
    header('Location: index.php');
    exit;
}

// Handle save/unsave (only when law is from DB)
if (!$fromJson && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!$auth->isLoggedIn()) {
        header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    $userId = $auth->getUserId();
    if ($_POST['action'] === 'save') {
        $db->saveLawForUser($userId, (int)$law['id']);
    } elseif ($_POST['action'] === 'unsave') {
        $db->removeSavedLawForUser($userId, (int)$law['id']);
    }
    header('Location: law.php?id=' . (int)$law['id']);
    exit;
}

$attachments = $fromJson ? [] : $db->getAttachments($law['id']);
$summary = null;
$hasFullSummary = false;

if (!empty($law['ai_summary'])) {
    $summary = json_decode($law['ai_summary'], true);
    if (is_array($summary)) {
        $summaryParagraph = trim((string) ($summary['summary_paragraph'] ?? ''));
        $hasFullSummary = ($summaryParagraph !== '');
    }
}

$defaultSummary = [
    'tags' => [],
    'summary_paragraph' => '',
    'affected_groups' => [],
    'positives' => [],
    'negatives' => [],
    'how_to_react' => [],
    'disclaimer' => ''
];

if (!$summary || json_last_error() !== JSON_ERROR_NONE) {
    $summary = $defaultSummary;
} else {
    $summary = array_merge($defaultSummary, $summary);
}

if (!$hasFullSummary) {
    $summary['summary_paragraph'] = 'Tento dokument ešte nemá AI analýzu. Kliknite na tlačidlo "Analyzovať" a analýza sa vygeneruje na požiadanie.';
}

// Ensure all expected fields have correct types
if (!isset($summary['tags']) || !is_array($summary['tags'])) {
    $summary['tags'] = [];
}
foreach (['affected_groups', 'positives', 'negatives', 'how_to_react'] as $key) {
    if (!isset($summary[$key]) || !is_array($summary[$key])) {
        $summary[$key] = [];
    }
}

$processingStatus = $law['processing_status'] ?? 'completed';
$textExtracted = isset($law['text_extracted']) ? (bool)$law['text_extracted'] : true;
$canAnalyzeOnDemand = !$fromJson && $textExtracted && !$hasFullSummary;

// Check if law is saved by user (only when from DB)
$isSaved = false;
if (!$fromJson && $auth->isLoggedIn()) {
    $isSaved = $db->isLawSavedByUser($auth->getUserId(), (int)$law['id']);
}

$isPaid = $auth->isPaid();
$pdfDownloadLimitReached = false;
$chatQuestionUsed = false;
if ($auth->isLoggedIn()) {
    $userId = $auth->getUserId();
    if (!$isPaid) {
        $pdfDownloadLimitReached = $db->getPdfDownloadCount($userId) >= 1;
    }
    if (!$isPaid) {
        if ($fromJson) {
            $masterIdForChat = (string)($law['master_id'] ?? $law['id']);
            $existingChat = $db->getChatByMasterId($userId, $masterIdForChat);
        } else {
            $existingChat = $db->getUserChat($userId, (int)$law['id']);
        }
        $msgs = $existingChat['messages'] ?? [];
        $userMsgCount = 0;
        foreach ($msgs as $m) {
            if (isset($m['role']) && $m['role'] === 'user') {
                $userMsgCount++;
            }
        }
        $chatQuestionUsed = $userMsgCount >= 1;
    }
}

// Chat panel: need law text from storage/.../combined.txt OR public/data/laws/{id}.txt (v6: committed by Actions for DO)
$chatAvailable = false;
$masterId = $law['master_id'] ?? $law['id'];
$txtInPublic = __DIR__ . '/data/laws/' . $masterId . '.txt';
if (is_readable($txtInPublic) && filesize($txtInPublic) > 0) {
    $chatAvailable = true;
}
if (!$chatAvailable && !$fromJson) {
    $storagePath = Config::get('STORAGE_PATH', 'storage');
    if (!str_starts_with($storagePath, '/')) {
        $storagePath = dirname(__DIR__) . '/' . $storagePath;
    }
    $origin = $law['origin'] ?? 'nrsr';
    if ($origin === 'slovlex_zz') {
        $extId = $law['external_id'] ?? '';
        if ($extId === '' && preg_match('/^slovlex-ZZ-(\d+)-(\d+)$/', $masterId, $m)) {
            $extId = $m[1] . '/' . $m[2];
        }
        $combinedPath = $storagePath . '/slovlex_zz/' . str_replace('\\', '/', $extId) . '/combined.txt';
    } else {
        $combinedPath = $storagePath . '/' . $masterId . '/combined.txt';
    }
    $chatAvailable = file_exists($combinedPath) && filesize($combinedPath) > 0;
}

?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title><?php echo htmlspecialchars($law['title']); ?> - Monitor zákona</title>
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
        
        <?php if ($auth->isLoggedIn() && !$fromJson && $isPaid): ?>
            <div class="lg-save-container">
                <form method="POST" action="" style="display: inline;">
                    <?php if ($isSaved): ?>
                        <button type="submit" name="action" value="unsave" class="lg-btn lg-btn-danger">
                            ✗ Odstrániť z Mojej pamäte
                        </button>
                    <?php else: ?>
                        <button type="submit" name="action" value="save" class="lg-btn lg-btn-success">
                            ✓ Uložiť do Mojej pamäte
                        </button>
                    <?php endif; ?>
                </form>
            </div>
        <?php elseif ($auth->isLoggedIn() && !$fromJson && !$isPaid): ?>
            <div class="lg-save-container lg-caption">
                Ukladanie do Mojej pamäte je súčasťou <a href="pricing.php" class="lg-link">platenej verzie</a>.
            </div>
        <?php endif; ?>
        
        <?php if (!empty($summary['tags']) && is_array($summary['tags'])): ?>
        <div class="lg-law-tags">
            <?php foreach ($summary['tags'] as $tag): ?>
                <?php $color = \App\OpenAIClient::getTagColor($tag); ?>
                <span class="lg-law-tag" style="background-color: <?php echo htmlspecialchars($color); ?>;">
                    <?php echo htmlspecialchars($tag); ?>
                </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($law['human_title'])): ?>
        <p class="lg-law-human-title" style="font-size:1.1rem;color:var(--text-secondary);margin-bottom:8px;"><?php echo htmlspecialchars($law['human_title']); ?></p>
        <?php endif; ?>
        <h1 class="lg-title" style="margin-bottom:15px;font-size:1.75rem;"><?php echo htmlspecialchars($law['title']); ?></h1>
        
        <div class="lg-meta">
            <?php if ($law['approval_date']): ?>
                <p><strong>Schválené:</strong> <?php echo htmlspecialchars($law['approval_date']); ?></p>
            <?php endif; ?>
            <p><strong>Zdroj:</strong> <a href="<?php echo htmlspecialchars($law['source_url']); ?>" target="_blank"><?php echo (isset($law['origin']) && $law['origin'] === 'slovlex_zz') ? 'Slov-Lex' : 'NR SR'; ?></a></p>
            <?php if (!$textExtracted && $processingStatus === 'no_text_extracted'): ?>
                <p style="color: #e67e22; font-weight: bold; margin-top: 10px;">
                    ⚠ Text z tohto zákona nebol možné automaticky extrahovať (naskenované dokumenty). Pre detailnú analýzu by bolo potrebné OCR.
                </p>
            <?php elseif ($textExtracted && $processingStatus === 'completed'): ?>
                <p style="color: #27ae60; font-weight: bold; margin-top: 10px;">
                    ✓ Text úspešne extrahovaný pomocou OCR technológie
                </p>
            <?php endif; ?>
        </div>

        <div class="lg-chat" style="margin-top:30px;padding-top:20px;border-top:1px solid var(--glass-border);">
            <div class="lg-section-title">Opýtajte sa zákona</div>
            <?php if (!$auth->isLoggedIn()): ?>
                <div class="lg-section-content lg-caption">
                    Pre opýtanie sa zákona sa <a href="login.php?redirect=<?php echo urlencode('law.php?id=' . ($law['id'] ?? '')); ?>" class="lg-link">prihláste</a> alebo <a href="register.php" class="lg-link">registrujte</a>.
                </div>
            <?php elseif ($chatAvailable): ?>
                <?php if ($chatQuestionUsed && !$isPaid): ?>
                    <div class="lg-section-content">
                        <p style="margin-bottom:12px;">Na ďalšie otázky k tomuto zákonu aktivujte platenú verziu.</p>
                        <a href="pricing.php" class="lg-btn lg-btn-success" style="display:inline-block;text-decoration:none;">Upgradovať na platenú verziu</a>
                    </div>
                <?php else: ?>
                    <div class="lg-section-content">
                        <?php if (!$isPaid): ?>
                            <p class="lg-caption" style="margin-bottom:10px;">Bezplatní používatelia: 1 otázka na zákon. Ďalšie po upgrade.</p>
                        <?php endif; ?>
                        <textarea id="law-chat-question" placeholder="Napíšte otázku k tomuto zákonu..."></textarea>
                        <div class="lg-actions">
                            <button id="law-chat-submit" type="button" class="lg-btn lg-btn-primary">Opýtať sa</button>
                            <button id="law-chat-download" class="lg-btn lg-btn-secondary" type="button">Stiahnuť PDF</button>
                            <button id="law-chat-reset" class="lg-btn lg-btn-secondary" type="button">Vymazať konverzáciu</button>
                        </div>
                        <?php if (!$isPaid): ?>
                            <p class="lg-caption" style="margin-top:8px;">Sťahovanie PDF: 1× zadarmo. Ďalšie po upgrade.</p>
                        <?php endif; ?>
                        <div id="law-chat-error" class="lg-error" style="display:none;margin-top:10px;"></div>
                        <div id="law-chat-thread" class="lg-chat-thread"></div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="lg-section-content">
                    Text zákona zatiaľ nie je dostupný pre chat. Skúste to neskôr po spracovaní.
                </div>
            <?php endif; ?>
        </div>

        <div class="lg-section">
            <div class="lg-section-title">Zhrnutie</div>
            <div class="lg-section-content">
                <?php if ($canAnalyzeOnDemand): ?>
                    <p style="margin-bottom: 12px;"><?php echo nl2br(htmlspecialchars($summary['summary_paragraph'] ?? '', ENT_QUOTES, 'UTF-8')); ?></p>
                    <?php if ($auth->isLoggedIn()): ?>
                        <button id="analyze-law-btn" type="button" class="lg-btn lg-btn-primary">Analyzovať</button>
                        <p id="analyze-law-status" class="lg-caption" style="margin-top:10px;display:none;"></p>
                    <?php else: ?>
                        <p class="lg-caption">Pre analýzu sa <a href="login.php?redirect=<?php echo urlencode('law.php?id=' . ($law['id'] ?? '')); ?>" class="lg-link">prihláste</a>.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <?php echo nl2br(htmlspecialchars($summary['summary_paragraph'] ?? '', ENT_QUOTES, 'UTF-8')); ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($summary['affected_groups'])): ?>
        <div class="lg-section">
            <div class="lg-section-title">Ovplyvnené skupiny</div>
            <div class="lg-section-content">
                <ul>
                    <?php foreach ($summary['affected_groups'] as $group): ?>
                        <li><?php echo htmlspecialchars($group); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($summary['positives'])): ?>
        <div class="lg-section">
            <div class="lg-section-title">Pozitíva</div>
            <div class="lg-section-content">
                <ul>
                    <?php foreach ($summary['positives'] as $positive): ?>
                        <li class="lg-positive"><?php echo htmlspecialchars($positive); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($summary['negatives'])): ?>
        <div class="lg-section">
            <div class="lg-section-title">Negatíva</div>
            <div class="lg-section-content">
                <ul>
                    <?php foreach ($summary['negatives'] as $negative): ?>
                        <li class="lg-negative"><?php echo htmlspecialchars($negative); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($summary['how_to_react'])): ?>
        <div class="lg-section">
            <div class="lg-section-title">Ako reagovať</div>
            <div class="lg-section-content">
                <ul>
                    <?php foreach ($summary['how_to_react'] as $reaction): ?>
                        <?php
                        if (is_string($reaction)) {
                            echo '<li>' . htmlspecialchars($reaction) . '</li>';
                        } elseif (is_array($reaction)) {
                            $advice = $reaction['advice'] ?? $reaction['reaction'] ?? $reaction['text'] ?? '';
                            $details = $reaction['details'] ?? $reaction['explanation'] ?? '';
                            if ($advice && $details) {
                                echo '<li><strong>' . htmlspecialchars($advice) . '</strong>: ' . htmlspecialchars($details) . '</li>';
                            } elseif ($advice) {
                                echo '<li>' . htmlspecialchars($advice) . '</li>';
                            } else {
                                echo '<li>' . htmlspecialchars(json_encode($reaction, JSON_UNESCAPED_UNICODE)) . '</li>';
                            }
                        } else {
                            echo '<li>' . htmlspecialchars(String($reaction)) . '</li>';
                        }
                        ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($attachments)): ?>
        <div class="lg-attachments">
            <div class="lg-section-title">Prílohy</div>
            <ul>
                <?php foreach ($attachments as $att): ?>
                    <li>
                        <?php echo htmlspecialchars($att['filename']); ?>
                        <?php if ($att['source_url']): ?>
                            (<a href="<?php echo htmlspecialchars($att['source_url']); ?>" target="_blank">zdroj</a>)
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (!empty($summary['disclaimer'])): ?>
        <div class="lg-disclaimer" style="margin-top:30px;">
            <?php echo nl2br(htmlspecialchars($summary['disclaimer'])); ?>
        </div>
        <?php endif; ?>

        <div class="lg-footer" style="margin-top: 40px;">
            <p>Automaticky monitorované z <a href="https://www.nrsr.sk/web/default.aspx?SectionId=184" target="_blank">NR SR</a></p>
            <p style="margin-top: 10px;">
                <a href="prompts.php">Použité prompty</a> | <a href="terms.php">Podmienky používania</a> | Autor: Matúš Kaník
            </p>
        </div>
    </div>
    <?php if ($chatAvailable && $auth->isLoggedIn() && (!$chatQuestionUsed || $isPaid)): ?>
    <script>
        const chatSubmit = document.getElementById('law-chat-submit');
        const chatDownload = document.getElementById('law-chat-download');
        const chatReset = document.getElementById('law-chat-reset');
        const chatQuestion = document.getElementById('law-chat-question');
        const chatThread = document.getElementById('law-chat-thread');
        const chatError = document.getElementById('law-chat-error');
        const storageKey = 'law-chat-<?php echo htmlspecialchars($law['id']); ?>';
        const pdfDownloadLimitReached = <?php echo $pdfDownloadLimitReached ? 'true' : 'false'; ?>;

        const loadHistory = () => {
            try {
                const raw = localStorage.getItem(storageKey);
                const parsed = raw ? JSON.parse(raw) : [];
                return Array.isArray(parsed) ? parsed : [];
            } catch (e) {
                return [];
            }
        };

        const saveHistory = (history) => {
            localStorage.setItem(storageKey, JSON.stringify(history));
        };

        const renderHistory = (history) => {
            chatThread.innerHTML = '';
            history.forEach((msg) => {
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
                chatThread.appendChild(item);
            });
        };

        let history = loadHistory();
        renderHistory(history);

        chatReset.addEventListener('click', () => {
            history = [];
            saveHistory(history);
            renderHistory(history);
            chatError.style.display = 'none';
            chatError.textContent = '';
        });

        chatDownload.addEventListener('click', async () => {
            if (pdfDownloadLimitReached) {
                window.location.href = 'pricing.php';
                return;
            }
            chatError.style.display = 'none';
            chatError.textContent = '';
            chatDownload.disabled = true;
            chatDownload.textContent = 'Pripravujem PDF...';
            try {
                const response = await fetch('law-pdf.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json; charset=UTF-8' },
                    body: JSON.stringify({
                        law_id: '<?php echo htmlspecialchars($law['id']); ?>',
                        history: history
                    })
                });
                if (!response.ok) {
                    const text = await response.text();
                    let data;
                    try { data = JSON.parse(text); } catch (e) { data = {}; }
                    if (data.limit_reached && data.redirect) {
                        window.location.href = data.redirect;
                        return;
                    }
                    throw new Error(data.error || text || 'Nepodarilo sa vygenerovať PDF.');
                }
                const blob = await response.blob();
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = 'zakon-<?php echo htmlspecialchars($law['id']); ?>.pdf';
                document.body.appendChild(link);
                link.click();
                link.remove();
                URL.revokeObjectURL(url);
            } catch (err) {
                chatError.textContent = err.message || 'Chyba pri generovaní PDF.';
                chatError.style.display = 'block';
            } finally {
                chatDownload.disabled = false;
                chatDownload.textContent = 'Stiahnuť PDF';
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
                const response = await fetch('law-chat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json; charset=UTF-8' },
                    body: JSON.stringify({
                        law_id: '<?php echo htmlspecialchars($law['id']); ?>',
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
                const answer = data.answer || 'AI nevrátila odpoveď.';
                const assistantMsg = { role: 'assistant', content: answer };
                if (Array.isArray(data.citations) && data.citations.length > 0) {
                    assistantMsg.citations = data.citations;
                }
                history = history.concat([assistantMsg]);
                saveHistory(history);
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
    <?php if ($canAnalyzeOnDemand && $auth->isLoggedIn()): ?>
    <script>
    (function() {
        var btn = document.getElementById('analyze-law-btn');
        var status = document.getElementById('analyze-law-status');
        if (!btn || !status) return;

        btn.addEventListener('click', async function () {
            status.style.display = 'block';
            status.textContent = 'Analyzujem dokument, prosím čakajte...';
            btn.disabled = true;

            try {
                var response = await fetch('analyze-law.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json; charset=UTF-8' },
                    body: JSON.stringify({ law_id: '<?php echo htmlspecialchars((string)($law['id'] ?? '')); ?>' })
                });
                var data = await response.json();
                if (!response.ok) {
                    throw new Error(data.error || 'Analýzu sa nepodarilo spustiť.');
                }

                status.textContent = 'Analýza je hotová. Obnovujem stránku...';
                window.location.reload();
            } catch (err) {
                status.textContent = err.message || 'Analýza zlyhala. Skúste to znova.';
                btn.disabled = false;
            }
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>

