<?php

require_once __DIR__ . '/../vendor/autoload.php';
\App\Maintenance::check();

use App\Config;
use App\Database;
use App\Auth;
use App\Security;

// Google Sign-In is usually configured for localhost in dev; normalize 127.0.0.1.
$hostHeader = $_SERVER['HTTP_HOST'] ?? '';
if (str_starts_with($hostHeader, '127.0.0.1')) {
    $targetHost = preg_replace('/^127\.0\.0\.1/', 'localhost', $hostHeader);
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/login.php';
    header('Location: http://' . $targetHost . $requestUri, true, 302);
    exit;
}

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
    $redirect = $_GET['redirect'] ?? 'index.php';
    header('Location: ' . $redirect);
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $result = $auth->login($email, $password);
    if ($result['success']) {
        $redirect = $_GET['redirect'] ?? 'index.php';
        header('Location: ' . $redirect);
        exit;
    } else {
        $error = $result['error'];
    }
}

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

?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Prihlásenie - Monitor zákona</title>
    <link rel="stylesheet" href="css/liquid-glass.css">
    <?php if ($googleClientId): ?>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <?php endif; ?>
    <style>.lg-google-wrap{width:100%;display:flex;justify-content:center;margin-bottom:20px;}.lg-google-disabled{width:100%;padding:14px;border:1px solid var(--input-border);border-radius:var(--radius-md);background:var(--surface-2);color:var(--text-tertiary);cursor:not-allowed;}.lg-register-link{text-align:center;margin-top:24px;color:var(--text-tertiary);}.lg-register-link a{color:var(--accent);}</style>
</head>
<body>
    <div class="lg-container lg-container-narrow">
        <h1 class="lg-title" style="margin-bottom:28px;text-align:center;">Prihlásenie</h1>
        
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
                    data-text="signin_with"
                    data-size="large"
                    data-logo_alignment="left">
                </div>
            </div>

            <div class="lg-divider">
                <span>alebo</span>
            </div>
        <?php else: ?>
            <div class="lg-google-wrap">
                <button class="lg-google-disabled" disabled>Prihlásiť sa cez Google</button>
            </div>
            <div class="lg-info">
                Google prihlásenie nie je zapnuté. Nastavte <code>GOOGLE_CLIENT_ID</code> v súbore <code>.env</code> (lokálne) alebo v <strong>premenných prostredia</strong> na Digital Ocean (Settings → App-Level Environment Variables) a znova nasaďte.
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="lg-form-group">
                <label for="email" class="lg-label">Email</label>
                <input type="email" id="email" name="email" class="lg-input" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            </div>
            <div class="lg-form-group">
                <label for="password" class="lg-label">Heslo</label>
                <input type="password" id="password" name="password" class="lg-input" required>
            </div>
            <button type="submit" class="lg-btn lg-btn-primary lg-submit">Prihlásiť sa</button>
        </form>

        <div class="lg-register-link">
            <a href="forgot-password.php">Zabudli ste heslo?</a><br>
            Nemáte účet? <a href="register.php">Registrovať sa</a>
        </div>
    </div>
</body>
</html>
