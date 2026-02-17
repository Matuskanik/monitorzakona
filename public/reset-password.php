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

$db = new Database(Config::get('DB_PATH', 'data/sentinel.db') ?: 'data/sentinel.db');
$auth = new Auth($db);

if ($auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$token = trim($_GET['token'] ?? '');
$error = null;
$success = false;
$row = null;

if (empty($token)) {
    $error = 'Chýba token. Použite odkaz z emailu.';
} else {
    $row = $db->findPasswordResetToken($token);
    if (!$row) {
        $error = 'Odkaz je neplatný alebo vypršal. Požiadajte o nový odkaz na obnovenie hesla.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = $_POST['password'] ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';
        if (strlen($password) < 8) {
            $error = 'Heslo musí mať minimálne 8 znakov.';
        } elseif ($password !== $passwordConfirm) {
            $error = 'Heslá sa nezhodujú.';
        } else {
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            $db->updateUserPassword((int) $row['user_id'], $passwordHash);
            $db->deletePasswordResetToken($token);
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
    <title>Nové heslo - Monitor zákona</title>
    <link rel="stylesheet" href="css/liquid-glass.css">
    <style>.lg-register-link{text-align:center;margin-top:24px;color:var(--text-tertiary);}.lg-register-link a{color:var(--accent);}</style>
</head>
<body>
    <div class="lg-container lg-container-narrow">
        <h1 class="lg-title" style="margin-bottom:28px;text-align:center;">Nové heslo</h1>

        <?php if ($error): ?>
            <div class="lg-error"><?php echo htmlspecialchars($error); ?></div>
            <div class="lg-register-link">
                <a href="forgot-password.php">Požiadať o nový odkaz</a> · <a href="login.php">Prihlásiť sa</a>
            </div>
        <?php elseif ($success): ?>
            <div class="lg-info">
                Vaše heslo bolo úspešne zmenené. Teraz sa môžete prihlásiť.
            </div>
            <div class="lg-register-link">
                <a href="login.php">Prihlásiť sa</a>
            </div>
        <?php elseif ($row): ?>
            <form method="POST" action="">
                <div class="lg-form-group">
                    <label for="password" class="lg-label">Nové heslo</label>
                    <input type="password" id="password" name="password" class="lg-input" required minlength="8" placeholder="Minimálne 8 znakov">
                </div>
                <div class="lg-form-group">
                    <label for="password_confirm" class="lg-label">Potvrdenie hesla</label>
                    <input type="password" id="password_confirm" name="password_confirm" class="lg-input" required minlength="8">
                </div>
                <button type="submit" class="lg-btn lg-btn-primary lg-submit">Nastaviť heslo</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
