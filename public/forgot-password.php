<?php

require_once __DIR__ . '/../vendor/autoload.php';
\App\Maintenance::check();

use App\Config;
use App\Database;
use App\Auth;
use App\Security;
use App\MailService;
use App\Logger;

try {
    Config::load();
} catch (\Exception $e) {
    die("Configuration error: " . htmlspecialchars($e->getMessage()));
}

Security::setSecurityHeaders();

$db = new Database(Config::get('DB_PATH', 'data/sentinel.db') ?: 'data/sentinel.db');
$auth = new Auth($db);

if ($auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = null;
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Zadajte platnú emailovú adresu.';
    } else {
        $user = $db->findUserByEmail($email);
        if (!$user) {
            $success = true;
        } elseif (empty($user['password_hash'])) {
            $success = true;
        } else {
            $token = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + 3600);
            $db->createPasswordResetToken((int) $user['id'], $token, $expiresAt);

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
                $protocol = 'https';
            }
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $scriptPath = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
            if (!empty($host)) {
                $baseUrl = rtrim($protocol . '://' . $host . $scriptPath, '/');
            } else {
                $baseUrl = rtrim(Config::get('APP_BASE_URL', 'https://monitorzakona.sk'), '/');
            }
            $resetLink = $baseUrl . '/reset-password.php?token=' . urlencode($token);

            $logPath = Config::get('LOG_PATH', __DIR__ . '/../storage/logs');
            if (!str_starts_with($logPath, '/')) {
                $logPath = dirname(__DIR__) . '/' . $logPath;
            }
            $mailer = new MailService(new Logger($logPath . '/app.log'));
            $sent = $mailer->sendPasswordResetEmail($email, $resetLink);

            $success = true;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Zabudnuté heslo - Monitor zákona</title>
    <link rel="stylesheet" href="css/liquid-glass.css">
    <style>.lg-register-link{text-align:center;margin-top:24px;color:var(--text-tertiary);}.lg-register-link a{color:var(--accent);}</style>
</head>
<body>
    <div class="lg-container lg-container-narrow">
        <h1 class="lg-title" style="margin-bottom:28px;text-align:center;">Zabudnuté heslo</h1>

        <?php if ($error): ?>
            <div class="lg-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="lg-info">
                Ak sa účet s touto emailovou adresou nachádza v našom systéme a má nastavené heslo, odoslali sme vám odkaz na obnovenie hesla. Skontrolujte svoju emailovú schránku (aj priečinok spam). Odkaz platí 1 hodinu.
            </div>
            <div class="lg-register-link">
                <a href="login.php">Späť na prihlásenie</a>
            </div>
        <?php else: ?>
            <p class="lg-caption" style="margin-bottom:20px;">Zadajte emailovú adresu vášho účtu. Pošleme vám odkaz na obnovenie hesla.</p>
            <form method="POST" action="">
                <div class="lg-form-group">
                    <label for="email" class="lg-label">Email</label>
                    <input type="email" id="email" name="email" class="lg-input" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                </div>
                <button type="submit" class="lg-btn lg-btn-primary lg-submit">Odoslať odkaz</button>
            </form>
            <div class="lg-register-link">
                <a href="login.php">Späť na prihlásenie</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
