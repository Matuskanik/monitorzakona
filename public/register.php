<?php

require_once __DIR__ . '/../vendor/autoload.php';

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

// Redirect if already logged in
if ($auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = null;
$success = false;
$recaptchaSiteKey = Config::get('RECAPTCHA_SITE_KEY', '');
$recaptchaSecret = Config::get('RECAPTCHA_SECRET_KEY', '');
$googleClientId = Config::get('GOOGLE_CLIENT_ID', '');
$host = $_SERVER['HTTP_HOST'] ?? 'monitorzakona.sk';
$protocol = 'http';
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $protocol = 'https';
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
    $protocol = 'https';
}
if (strpos($host, 'monitorzakona.sk') !== false) {
    $protocol = 'https'; // production always HTTPS (Google requires secure login_uri)
}
$scriptPath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$redirectUri = $protocol . '://' . $host . $scriptPath . '/google-callback.php';
$loginUri = $redirectUri;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $termsAccepted = isset($_POST['terms']);
    $captchaResponse = $_POST['g-recaptcha-response'] ?? '';

    // Validate captcha only when configured
    if ($recaptchaSiteKey && $recaptchaSecret) {
        if (empty($captchaResponse)) {
            $error = 'Prosím potvrďte, že nie ste robot';
        } else {
            $verifyUrl = "https://www.google.com/recaptcha/api/siteverify";
            $data = [
                'secret' => $recaptchaSecret,
                'response' => $captchaResponse,
                'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
            ];

            $options = [
                'http' => [
                    'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method' => 'POST',
                    'content' => http_build_query($data)
                ]
            ];

            $context = stream_context_create($options);
            $result = file_get_contents($verifyUrl, false, $context);
            $resultJson = json_decode($result, true);

            if (!$resultJson || !($resultJson['success'] ?? false)) {
                $error = 'Captcha overenie zlyhalo. Skúste znova.';
            }
        }
    }

    // Validate password match
    if (!$error && $password !== $passwordConfirm) {
        $error = 'Heslá sa nezhodujú';
    }

    // Register user
    if (!$error) {
        $result = $auth->register($email, $password, $termsAccepted);
        if ($result['success']) {
            $logPath = Config::get('LOG_PATH', __DIR__ . '/../storage/logs');
            if (!str_starts_with($logPath, '/')) {
                $logPath = dirname(__DIR__) . '/' . $logPath;
            }
            $mailer = new MailService(new Logger($logPath . '/app.log'));
            $mailer->sendWelcomeEmail($email);
            $success = true;
            header('Location: index.php?registered=1');
            exit;
        } else {
            $error = $result['error'];
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
    <title>Registrácia - Monitor zákona</title>
    <link rel="stylesheet" href="css/liquid-glass.css">
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php if ($googleClientId): ?>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <?php endif; ?>
    <style>.lg-checkbox-group{display:flex;align-items:flex-start;gap:10px;margin-bottom:20px;}.lg-checkbox-group input[type=checkbox]{margin-top:4px;flex-shrink:0;}.lg-checkbox-group label{margin-bottom:0;font-weight:normal;}.lg-recaptcha{display:flex;justify-content:center;margin:20px 0;}.lg-google-wrap{width:100%;display:flex;justify-content:center;margin-bottom:20px;}.lg-google-disabled{width:100%;padding:14px;border:1px solid var(--input-border);border-radius:var(--radius-md);background:var(--surface-2);color:var(--text-tertiary);cursor:not-allowed;}.lg-register-link{text-align:center;margin-top:24px;color:var(--text-tertiary);}.lg-register-link a{color:var(--accent);}</style>
</head>
<body>
    <div class="lg-container lg-container-narrow">
        <h1 class="lg-title" style="margin-bottom:28px;text-align:center;">Registrácia</h1>
        
        <?php if ($error): ?>
            <div class="lg-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($googleClientId): ?>
            <div class="lg-google-wrap">
                <div id="g_id_onload"
                    data-client_id="<?php echo htmlspecialchars($googleClientId); ?>"
                    data-context="signin"
                    data-ux_mode="redirect"
                    data-login_uri="<?php echo htmlspecialchars($loginUri); ?>"
                    data-auto_prompt="false">
                </div>
                <div class="g_id_signin"
                    data-type="standard"
                    data-shape="rectangular"
                    data-theme="outline"
                    data-text="signup_with"
                    data-size="large"
                    data-logo_alignment="left">
                </div>
            </div>

            <div class="lg-divider">
                <span>alebo</span>
            </div>
        <?php else: ?>
            <div class="lg-google-wrap">
                <button class="lg-google-disabled" disabled>Zaregistrovať sa cez Google</button>
            </div>
            <div class="lg-info">
                Google registrácia nie je zapnutá. Nastavte <code>GOOGLE_CLIENT_ID</code> v súbore <code>.env</code> (lokálne) alebo v <strong>premenných prostredia</strong> na Digital Ocean (Settings → App-Level Environment Variables) a znova nasaďte.
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="lg-form-group">
                <label for="email" class="lg-label">Email</label>
                <input type="email" id="email" name="email" class="lg-input" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            <div class="lg-form-group">
                <label for="password" class="lg-label">Heslo</label>
                <input type="password" id="password" name="password" class="lg-input" required minlength="8" placeholder="Minimálne 8 znakov">
            </div>
            <div class="lg-form-group">
                <label for="password_confirm" class="lg-label">Potvrdenie hesla</label>
                <input type="password" id="password_confirm" name="password_confirm" class="lg-input" required minlength="8">
            </div>
            <div class="lg-checkbox-group">
                <input type="checkbox" id="terms" name="terms" required>
                <label for="terms">
                    Súhlasím s <a href="terms.php" target="_blank" class="lg-link">podmienkami používania</a>
                </label>
            </div>
            <?php if ($recaptchaSiteKey): ?>
                <div class="lg-recaptcha">
                    <div class="g-recaptcha" data-sitekey="<?php echo htmlspecialchars($recaptchaSiteKey); ?>"></div>
                </div>
            <?php endif; ?>
            <button type="submit" class="lg-btn lg-btn-primary lg-submit">Registrovať sa</button>
        </form>

        <div class="lg-register-link">
            Už máte účet? <a href="login.php">Prihlásiť sa</a>
        </div>
    </div>
</body>
</html>
