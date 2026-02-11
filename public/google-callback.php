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

// Get the credential from POST
$credential = $_POST['credential'] ?? null;
$googleClientId = Config::get('GOOGLE_CLIENT_ID', '');

if (!$credential) {
    header('Location: login.php?error=no_credential');
    exit;
}

if (empty($googleClientId)) {
    header('Location: login.php?error=google_not_configured');
    exit;
}

// Verify the JWT token from Google using the official client
$client = new \Google_Client(['client_id' => $googleClientId]);
$payload = $client->verifyIdToken($credential);

if (!$payload) {
    header('Location: login.php?error=invalid_token');
    exit;
}

$googleId = $payload['sub'] ?? null;
$email = $payload['email'] ?? null;
$name = $payload['name'] ?? null;
$emailVerified = $payload['email_verified'] ?? false;

if (!$googleId || !$email || !$emailVerified) {
    header('Location: login.php?error=missing_info');
    exit;
}

// Login or register user
$existingUser = $db->findUserByEmail($email) ?: $db->findUserByGoogleId($googleId);
$result = $auth->loginWithGoogle($googleId, $email, $name);

if ($result['success']) {
    if (!$existingUser) {
        $logPath = Config::get('LOG_PATH', __DIR__ . '/../storage/logs');
        if (!str_starts_with($logPath, '/')) {
            $logPath = dirname(__DIR__) . '/' . $logPath;
        }
        $mailer = new MailService(new Logger($logPath . '/app.log'));
        $mailer->sendWelcomeEmail($email);
    }
    $redirect = $_GET['redirect'] ?? 'index.php';
    if (preg_match('/^https?:\\/\\//i', $redirect)) {
        $redirect = 'index.php';
    }
    header('Location: ' . $redirect);
    exit;
} else {
    header('Location: login.php?error=login_failed');
    exit;
}
