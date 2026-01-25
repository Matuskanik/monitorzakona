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

$lawId = $_GET['id'] ?? null;
$masterId = $_GET['master_id'] ?? null;

if (!$lawId && !$masterId) {
    header('Location: index.php');
    exit;
}

$db = new Database(Config::get('DB_PATH'));

// Support both id and master_id parameters
if ($masterId) {
    $law = $db->getPdo()->prepare("SELECT * FROM laws WHERE master_id = ?");
    $law->execute([$masterId]);
} else {
    $law = $db->getPdo()->prepare("SELECT * FROM laws WHERE id = ?");
    $law->execute([$lawId]);
}
$law = $law->fetch();

if (!$law) {
    header('Location: index.php');
    exit;
}

$attachments = $db->getAttachments($law['id']);
$summary = null;

if (!empty($law['ai_summary'])) {
    $summary = json_decode($law['ai_summary'], true);
}

if (!$summary || json_last_error() !== JSON_ERROR_NONE) {
    $processingStatus = $law['processing_status'] ?? 'pending';
    $statusMessage = ($processingStatus === 'pending') 
        ? 'Zákon čaká na spracovanie. Spustite <code>php bin/reprocess-law.php ' . htmlspecialchars($law['id']) . '</code> na prepracovanie s novými promptmi.'
        : 'Spracovanie prebieha...';
    
    $summary = [
        'tags' => [],
        'summary_paragraph' => $statusMessage,
        'affected_groups' => [],
        'positives' => [],
        'negatives' => [],
        'how_to_react' => [],
        'disclaimer' => ''
    ];
}

// Ensure tags array exists
if (!isset($summary['tags']) || !is_array($summary['tags'])) {
    $summary['tags'] = [];
}

$processingStatus = $law['processing_status'] ?? 'completed';
$textExtracted = isset($law['text_extracted']) ? (bool)$law['text_extracted'] : true;
$storagePath = Config::get('STORAGE_PATH', 'storage');
if (!str_starts_with($storagePath, '/')) {
    $storagePath = dirname(__DIR__) . '/' . $storagePath;
}
$combinedPath = $storagePath . '/' . $law['master_id'] . '/combined.txt';
$chatAvailable = file_exists($combinedPath) && filesize($combinedPath) > 0;

?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($law['title']); ?> - Monitor zákona</title>
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
            max-width: 900px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 1.8em;
        }
        .meta {
            color: #7f8c8d;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #ecf0f1;
        }
        .meta a {
            color: #3498db;
            text-decoration: none;
        }
        .meta a:hover {
            text-decoration: underline;
        }
        .section {
            margin-bottom: 35px;
        }
        .section-title {
            font-size: 1.3em;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid #3498db;
        }
        .section-content {
            color: #555;
            line-height: 1.8;
        }
        .section-content ul {
            margin-left: 20px;
            margin-top: 10px;
        }
        .section-content li {
            margin-bottom: 8px;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #3498db;
            text-decoration: none;
            font-size: 0.9em;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .attachments {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ecf0f1;
        }
        .attachments ul {
            list-style: none;
            margin-left: 0;
        }
        .attachments li {
            padding: 8px 0;
            color: #7f8c8d;
        }
        .attachments a {
            color: #3498db;
            text-decoration: none;
        }
        .attachments a:hover {
            text-decoration: underline;
        }
        .disclaimer {
            margin-top: 30px;
            padding: 15px;
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            color: #856404;
            font-size: 0.9em;
        }
        .positive-item {
            color: #27ae60;
        }
        .negative-item {
            color: #e74c3c;
        }
        .law-tags {
            margin-bottom: 15px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .law-tag {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 0.8em;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: white;
        }
        .law-chat {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #ecf0f1;
        }
        .law-chat .chat-thread {
            margin-top: 15px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .law-chat .chat-message {
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #ecf0f1;
            white-space: pre-wrap;
        }
        .law-chat .chat-message.user {
            background: #eaf2fb;
            align-self: flex-end;
        }
        .law-chat .chat-message.assistant {
            background: #f5f7fa;
            align-self: flex-start;
        }
        .law-chat .chat-meta {
            font-size: 0.75em;
            color: #7f8c8d;
            margin-bottom: 4px;
        }
        .law-chat textarea {
            width: 100%;
            min-height: 120px;
            padding: 12px;
            border: 1px solid #dcdfe3;
            border-radius: 6px;
            resize: vertical;
            font-family: inherit;
            font-size: 0.95em;
        }
        .law-chat .actions {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-top: 10px;
        }
        .law-chat button {
            margin-top: 10px;
            padding: 10px 16px;
            background: #3498db;
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
        }
        .law-chat button.secondary {
            background: #ecf0f1;
            color: #2c3e50;
        }
        .law-chat button:disabled {
            background: #95a5a6;
            cursor: not-allowed;
        }
        .law-chat .error {
            margin-top: 10px;
            color: #e74c3c;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link">← Späť na zoznam</a>
        
        <?php if (!empty($summary['tags']) && is_array($summary['tags'])): ?>
        <div class="law-tags">
            <?php foreach ($summary['tags'] as $tag): ?>
                <?php 
                $color = \App\OpenAIClient::getTagColor($tag);
                ?>
                <span class="law-tag" style="background-color: <?php echo htmlspecialchars($color); ?>;">
                    <?php echo htmlspecialchars($tag); ?>
                </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <h1><?php echo htmlspecialchars($law['title']); ?></h1>
        
        <div class="meta">
            <?php if ($law['approval_date']): ?>
                <p><strong>Schválené:</strong> <?php echo htmlspecialchars($law['approval_date']); ?></p>
            <?php endif; ?>
            <p><strong>Zdroj:</strong> <a href="<?php echo htmlspecialchars($law['source_url']); ?>" target="_blank">NR SR</a></p>
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

        <div class="law-chat">
            <div class="section-title">Opýtajte sa zákona</div>
            <?php if ($chatAvailable): ?>
                <div class="section-content">
                    <textarea id="law-chat-question" placeholder="Napíšte otázku k tomuto zákonu..."></textarea>
                    <div class="actions">
                        <button id="law-chat-submit" type="button">Opýtať sa</button>
                        <button id="law-chat-download" class="secondary" type="button">Stiahnuť PDF</button>
                        <button id="law-chat-reset" class="secondary" type="button">Vymazať konverzáciu</button>
                    </div>
                    <div id="law-chat-error" class="error" style="display:none;"></div>
                    <div id="law-chat-thread" class="chat-thread"></div>
                </div>
            <?php else: ?>
                <div class="section-content">
                    Text zákona zatiaľ nie je dostupný pre chat. Skúste to neskôr po spracovaní.
                </div>
            <?php endif; ?>
        </div>

        <div class="section">
            <div class="section-title">Zhrnutie</div>
            <div class="section-content">
                <?php echo nl2br(htmlspecialchars($summary['summary_paragraph'])); ?>
            </div>
        </div>

        <?php if (!empty($summary['affected_groups'])): ?>
        <div class="section">
            <div class="section-title">Ovplyvnené skupiny</div>
            <div class="section-content">
                <ul>
                    <?php foreach ($summary['affected_groups'] as $group): ?>
                        <li><?php echo htmlspecialchars($group); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($summary['positives'])): ?>
        <div class="section">
            <div class="section-title">Pozitíva</div>
            <div class="section-content">
                <ul>
                    <?php foreach ($summary['positives'] as $positive): ?>
                        <li class="positive-item"><?php echo htmlspecialchars($positive); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($summary['negatives'])): ?>
        <div class="section">
            <div class="section-title">Negatíva</div>
            <div class="section-content">
                <ul>
                    <?php foreach ($summary['negatives'] as $negative): ?>
                        <li class="negative-item"><?php echo htmlspecialchars($negative); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($summary['how_to_react'])): ?>
        <div class="section">
            <div class="section-title">Ako reagovať</div>
            <div class="section-content">
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
        <div class="attachments">
            <div class="section-title">Prílohy</div>
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
        <div class="disclaimer">
            <?php echo nl2br(htmlspecialchars($summary['disclaimer'])); ?>
        </div>
        <?php endif; ?>

        <div class="footer" style="margin-top: 40px; padding-top: 20px; border-top: 1px solid #ecf0f1; text-align: center; color: #95a5a6; font-size: 0.9em;">
            <p>Automaticky monitorované z <a href="https://www.nrsr.sk/web/default.aspx?SectionId=184" target="_blank" style="color: #3498db; text-decoration: none;">NR SR</a></p>
            <p style="margin-top: 10px;">
                <a href="prompts.php" style="color: #3498db; text-decoration: none;">Použité prompty</a> | Autor: Matúš Kaník
            </p>
        </div>
    </div>
    <?php if ($chatAvailable): ?>
    <script>
        const chatSubmit = document.getElementById('law-chat-submit');
        const chatDownload = document.getElementById('law-chat-download');
        const chatReset = document.getElementById('law-chat-reset');
        const chatQuestion = document.getElementById('law-chat-question');
        const chatThread = document.getElementById('law-chat-thread');
        const chatError = document.getElementById('law-chat-error');
        const storageKey = 'law-chat-<?php echo htmlspecialchars($law['id']); ?>';

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
                item.className = `chat-message ${msg.role}`;

                const meta = document.createElement('div');
                meta.className = 'chat-meta';
                meta.textContent = msg.role === 'user' ? 'Vy' : 'AI';

                const content = document.createElement('div');
                content.textContent = msg.content;

                item.appendChild(meta);
                item.appendChild(content);
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
                        history
                    })
                });

                if (!response.ok) {
                    const errorText = await response.text();
                    throw new Error(errorText || 'Nepodarilo sa vygenerovať PDF.');
                }

                const blob = await response.blob();
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                link.href = url;
                link.download = `zakon-<?php echo htmlspecialchars($law['id']); ?>.pdf`;
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
            history = [...history, { role: 'user', content: question }];
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
                        question,
                        history: historyForRequest
                    })
                });

                const data = await response.json();
                if (!response.ok) {
                    throw new Error(data.error || 'Neznáma chyba.');
                }

                const answer = data.answer || 'AI nevrátila odpoveď.';
                history = [...history, { role: 'assistant', content: answer }];
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
</body>
</html>

