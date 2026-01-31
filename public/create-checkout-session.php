<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\Auth;
use App\Security;

try {
    Config::load();
} catch (\Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Configuration error.';
    exit;
}

Security::setSecurityHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pricing.php');
    exit;
}

$db = new Database(Config::get('DB_PATH', 'data/sentinel.db') ?: 'data/sentinel.db');
$auth = new Auth($db);

if (!$auth->isLoggedIn()) {
    header('Location: login.php?redirect=' . urlencode('pricing.php'));
    exit;
}

$plan = trim($_POST['plan'] ?? '');
if (!in_array($plan, ['monthly', 'yearly'], true)) {
    header('Location: pricing.php');
    exit;
}

$secretKey = Config::get('STRIPE_SECRET_KEY');
$priceMonthly = Config::get('STRIPE_PRICE_MONTHLY');
$priceYearly = Config::get('STRIPE_PRICE_YEARLY');
$baseUrl = rtrim(Config::get('APP_BASE_URL', 'https://monitorzakona.sk'), '/');

if (empty($secretKey) || empty($priceMonthly) || empty($priceYearly)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Stripe is not configured.';
    exit;
}

$priceId = $plan === 'yearly' ? $priceYearly : $priceMonthly;

\Stripe\Stripe::setApiKey($secretKey);

$userId = $auth->getUserId();
$user = $auth->getUser();
$email = $user['email'] ?? '';
$stripeCustomerId = $user['stripe_customer_id'] ?? null;

if (empty($stripeCustomerId)) {
    try {
        $customer = \Stripe\Customer::create([
            'email' => $email,
        ]);
        $stripeCustomerId = $customer->id;
        $db->setStripeCustomerId($userId, $stripeCustomerId);
    } catch (\Exception $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Failed to create customer.';
        exit;
    }
}

try {
    $session = \Stripe\Checkout\Session::create([
        'customer' => $stripeCustomerId,
        'mode' => 'subscription',
        'payment_method_types' => ['card'],
        'line_items' => [
            [
                'price' => $priceId,
                'quantity' => 1,
            ],
        ],
        'success_url' => $baseUrl . '/pricing.php?success=1&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $baseUrl . '/pricing.php?canceled=1',
        'metadata' => [
            'user_id' => (string) $userId,
        ],
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Failed to create checkout session.';
    exit;
}

header('Location: ' . $session->url);
exit;
