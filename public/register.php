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
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$scriptPath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
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
    <title>Registrácia - Monitor zákona</title>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php if ($googleClientId): ?>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <?php endif; ?>
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
            max-width: 500px;
            margin: 50px auto;
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            margin-bottom: 30px;
            color: #2c3e50;
            text-align: center;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            color: #34495e;
            font-weight: 500;
        }
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px 16px;
            font-size: 1em;
            border: 2px solid #ddd;
            border-radius: 6px;
            transition: border-color 0.3s;
        }
        input[type="email"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #3498db;
        }
        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-bottom: 20px;
        }
        .checkbox-group input[type="checkbox"] {
            margin-top: 4px;
            flex-shrink: 0;
        }
        .checkbox-group label {
            margin-bottom: 0;
            font-weight: normal;
        }
        .recaptcha-container {
            margin: 20px 0;
            display: flex;
            justify-content: center;
        }
        .submit-button {
            width: 100%;
            padding: 14px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 1em;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s;
        }
        .submit-button:hover {
            background: #2980b9;
        }
        .error {
            background: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #c33;
        }
        .info {
            background: #eef6ff;
            color: #2c3e50;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #3498db;
            font-size: 0.95em;
        }
        .divider {
            text-align: center;
            margin: 30px 0;
            position: relative;
            color: #7f8c8d;
        }
        .divider::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            width: 100%;
            height: 1px;
            background: #ddd;
        }
        .divider span {
            background: white;
            padding: 0 15px;
            position: relative;
        }
        .google-button {
            width: 100%;
            display: flex;
            justify-content: center;
            margin-bottom: 20px;
        }
        .google-disabled {
            width: 100%;
            padding: 12px;
            border: 2px solid #ddd;
            border-radius: 6px;
            background: #f5f5f5;
            color: #7f8c8d;
            font-size: 0.95em;
            cursor: not-allowed;
        }
        .login-link {
            text-align: center;
            margin-top: 20px;
            color: #7f8c8d;
        }
        .login-link a {
            color: #3498db;
            text-decoration: none;
        }
        .login-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Registrácia</h1>
        
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($googleClientId): ?>
            <div class="google-button">
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

            <div class="divider">
                <span>alebo</span>
            </div>
        <?php else: ?>
            <div class="google-button">
                <button class="google-disabled" disabled>Zaregistrovať sa cez Google</button>
            </div>
            <div class="info">
                Google registrácia nie je nakonfigurovaná. Nastavte <code>GOOGLE_CLIENT_ID</code> v súbore <code>.env</code> a reštartujte server.
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="email">Email</label>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    required 
                    value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                >
            </div>

            <div class="form-group">
                <label for="password">Heslo</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    required 
                    minlength="8"
                    placeholder="Minimálne 8 znakov"
                >
            </div>

            <div class="form-group">
                <label for="password_confirm">Potvrdenie hesla</label>
                <input 
                    type="password" 
                    id="password_confirm" 
                    name="password_confirm" 
                    required 
                    minlength="8"
                >
            </div>

            <div class="checkbox-group">
                <input type="checkbox" id="terms" name="terms" required>
                <label for="terms">
                    Súhlasím s <a href="terms.php" target="_blank">podmienkami používania</a>
                </label>
            </div>

            <?php if ($recaptchaSiteKey): ?>
                <div class="recaptcha-container">
                    <div class="g-recaptcha" data-sitekey="<?php echo htmlspecialchars($recaptchaSiteKey); ?>"></div>
                </div>
            <?php endif; ?>

            <button type="submit" class="submit-button">Registrovať sa</button>
        </form>

        <div class="login-link">
            Už máte účet? <a href="login.php">Prihlásiť sa</a>
        </div>
    </div>
</body>
</html>
