<?php

// Suppress HTML error output - return text errors instead
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Global exception handler
set_exception_handler(function($e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Chyba: ' . $e->getMessage();
    exit;
});

// Custom error handler
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use Dompdf\Dompdf;
use Dompdf\Options;

try {
    Config::load();
} catch (\Exception $e) {
    http_response_code(500);
    echo 'Configuration error: ' . $e->getMessage();
    exit;
}

$payload = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

$lawId = $payload['law_id'] ?? ($_POST['law_id'] ?? null);
$history = $payload['history'] ?? [];

if (empty($lawId)) {
    http_response_code(400);
    echo 'Missing law id.';
    exit;
}

$db = new Database(Config::get('DB_PATH', 'data/sentinel.db') ?: 'data/sentinel.db');
// Support both numeric id and master_id
$stmt = $db->getPdo()->prepare("SELECT * FROM laws WHERE id = ? OR master_id = ?");
$stmt->execute([$lawId, $lawId]);
$law = $stmt->fetch();

if (!$law) {
    http_response_code(404);
    echo 'Law not found.';
    exit;
}

$attachments = $db->getAttachments($law['id']);
$summary = null;

if (!empty($law['ai_summary'])) {
    $summary = json_decode($law['ai_summary'], true);
}

if (!$summary || json_last_error() !== JSON_ERROR_NONE) {
    $summary = [
        'tags' => [],
        'summary_paragraph' => 'Zákon zatiaľ nemá dostupnú analýzu.',
        'affected_groups' => [],
        'positives' => [],
        'negatives' => [],
        'how_to_react' => [],
        'disclaimer' => ''
    ];
}

if (!isset($summary['tags']) || !is_array($summary['tags'])) {
    $summary['tags'] = [];
}

$sanitizedHistory = [];
if (is_array($history)) {
    foreach ($history as $item) {
        if (!is_array($item)) {
            continue;
        }
        $role = $item['role'] ?? '';
        $content = $item['content'] ?? '';
        if (!in_array($role, ['user', 'assistant'], true)) {
            continue;
        }
        if (!is_string($content)) {
            continue;
        }
        $content = trim($content);
        if ($content === '') {
            continue;
        }
        if (mb_strlen($content, 'UTF-8') > 4000) {
            $content = mb_substr($content, 0, 4000, 'UTF-8');
        }
        $sanitizedHistory[] = [
            'role' => $role,
            'content' => $content
        ];
    }
}

$logoPath = __DIR__ . '/logo.png';
$logoData = '';
if (file_exists($logoPath)) {
    $logoData = 'data:image/png;base64,' . base64_encode((string)file_get_contents($logoPath));
}

$title = htmlspecialchars($law['title'] ?? '', ENT_QUOTES, 'UTF-8');
$approvalDate = htmlspecialchars($law['approval_date'] ?? '', ENT_QUOTES, 'UTF-8');
$sourceUrl = htmlspecialchars($law['source_url'] ?? '', ENT_QUOTES, 'UTF-8');

$summaryParagraph = nl2br(htmlspecialchars($summary['summary_paragraph'] ?? '', ENT_QUOTES, 'UTF-8'));
$disclaimer = nl2br(htmlspecialchars($summary['disclaimer'] ?? '', ENT_QUOTES, 'UTF-8'));

$renderList = function (array $items): string {
    $html = '<ul>';
    foreach ($items as $item) {
        $html .= '<li>' . htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $html .= '</ul>';
    return $html;
};

$html = '<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: "DejaVu Sans", Arial, sans-serif;
            color: #333;
            font-size: 12px;
            line-height: 1.6;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        .header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }
        .logo {
            width: 180px;
        }
        h1 {
            font-size: 20px;
            margin: 10px 0 5px;
        }
        .meta {
            color: #666;
            margin-bottom: 15px;
        }
        .section {
            margin-top: 18px;
        }
        .section-title {
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 6px;
            border-bottom: 2px solid #3498db;
            padding-bottom: 4px;
        }
        ul {
            margin: 0 0 0 18px;
            padding: 0;
        }
        .tags {
            margin-top: 6px;
        }
        .tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            background: #3498db;
            color: #fff;
            font-size: 10px;
            margin-right: 6px;
            text-transform: uppercase;
        }
        .disclaimer {
            margin-top: 16px;
            padding: 10px;
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            color: #856404;
        }
        .chat-message {
            margin-bottom: 8px;
            padding: 8px 10px;
            border-radius: 6px;
            border: 1px solid #ecf0f1;
        }
        .chat-message.user {
            background: #eaf2fb;
        }
        .chat-message.assistant {
            background: #f5f7fa;
        }
        .chat-meta {
            font-size: 10px;
            color: #7f8c8d;
            margin-bottom: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">';

if ($logoData !== '') {
    $html .= '<img class="logo" src="' . $logoData . '" alt="Monitor zákona">';
}

$html .= '<div>
                <h1>' . $title . '</h1>
                <div class="meta">';

if ($approvalDate !== '') {
    $html .= '<div><strong>Schválené:</strong> ' . $approvalDate . '</div>';
}

if ($sourceUrl !== '') {
    $html .= '<div><strong>Zdroj:</strong> ' . $sourceUrl . '</div>';
}

if (!empty($summary['tags'])) {
    $html .= '<div class="tags">';
    foreach ($summary['tags'] as $tag) {
        $html .= '<span class="tag">' . htmlspecialchars((string)$tag, ENT_QUOTES, 'UTF-8') . '</span>';
    }
    $html .= '</div>';
}

$html .= '</div>
            </div>
        </div>

        <div class="section">
            <div class="section-title">Zhrnutie</div>
            <div>' . $summaryParagraph . '</div>
        </div>';

if (!empty($summary['affected_groups'])) {
    $html .= '<div class="section">
            <div class="section-title">Ovplyvnené skupiny</div>
            ' . $renderList($summary['affected_groups']) . '
        </div>';
}

if (!empty($summary['positives'])) {
    $html .= '<div class="section">
            <div class="section-title">Pozitíva</div>
            ' . $renderList($summary['positives']) . '
        </div>';
}

if (!empty($summary['negatives'])) {
    $html .= '<div class="section">
            <div class="section-title">Negatíva</div>
            ' . $renderList($summary['negatives']) . '
        </div>';
}

if (!empty($summary['how_to_react'])) {
    $html .= '<div class="section">
            <div class="section-title">Ako reagovať</div>
            ' . $renderList($summary['how_to_react']) . '
        </div>';
}

if (!empty($attachments)) {
    $html .= '<div class="section">
            <div class="section-title">Prílohy</div>
            <ul>';
    foreach ($attachments as $att) {
        $html .= '<li>' . htmlspecialchars((string)$att['filename'], ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $html .= '</ul>
        </div>';
}

if (!empty($sanitizedHistory)) {
    $html .= '<div class="section">
            <div class="section-title">Konverzácia</div>';
    foreach ($sanitizedHistory as $message) {
        $roleLabel = $message['role'] === 'user' ? 'Vy' : 'AI';
        $html .= '<div class="chat-message ' . htmlspecialchars($message['role'], ENT_QUOTES, 'UTF-8') . '">
                <div class="chat-meta">' . $roleLabel . '</div>
                <div>' . nl2br(htmlspecialchars($message['content'], ENT_QUOTES, 'UTF-8')) . '</div>
            </div>';
    }
    $html .= '</div>';
}

if ($disclaimer !== '') {
    $html .= '<div class="disclaimer">' . $disclaimer . '</div>';
}

$html .= '</div>
</body>
</html>';

try {
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $tempDir = sys_get_temp_dir() . '/dompdf';
    if (!is_dir($tempDir)) {
        @mkdir($tempDir, 0755, true);
    }
    $options->set('fontDir', $tempDir);
    $options->set('fontCache', $tempDir);
    $options->set('tempDir', $tempDir);
    $options->set('chroot', __DIR__);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    $dompdf->stream('zakon-' . $law['id'] . '.pdf', ['Attachment' => true]);
} catch (\Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'PDF generation error: ' . $e->getMessage();
}
exit;
