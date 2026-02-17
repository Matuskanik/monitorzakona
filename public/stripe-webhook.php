<?php

// Webhook must return 200 quickly; no HTML output
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
\App\Maintenance::check();

use App\Config;
use App\Database;

try {
    Config::load();
} catch (\Exception $e) {
    http_response_code(500);
    exit;
}

$payload = @file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

$secret = Config::get('STRIPE_WEBHOOK_SECRET');
if (empty($secret) || empty($payload)) {
    http_response_code(400);
    exit;
}

\Stripe\Stripe::setApiKey(Config::get('STRIPE_SECRET_KEY'));

$event = null;
try {
    $event = \Stripe\Webhook::constructEvent($payload, $sigHeader, $secret);
} catch (\UnexpectedValueException $e) {
    http_response_code(400);
    exit;
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit;
}

$db = new Database(Config::get('DB_PATH', 'data/sentinel.db') ?: 'data/sentinel.db');

if ($event->type === 'customer.subscription.created' || $event->type === 'customer.subscription.updated') {
    $subscription = $event->data->object;
    $customerId = $subscription->customer;
    $status = $subscription->status ?? '';

    $user = $db->findUserByStripeCustomerId($customerId);
    if (!$user) {
        http_response_code(200);
    } else {
        $plan = null;
        if (!empty($subscription->items->data[0]->price->id)) {
            $priceId = $subscription->items->data[0]->price->id;
            $priceMonthly = Config::get('STRIPE_PRICE_MONTHLY');
            $priceYearly = Config::get('STRIPE_PRICE_YEARLY');
            if ($priceId === $priceYearly) {
                $plan = 'yearly';
            } elseif ($priceId === $priceMonthly) {
                $plan = 'monthly';
            }
        }
        $periodEnd = null;
        if (!empty($subscription->current_period_end)) {
            $periodEnd = date('Y-m-d H:i:s', $subscription->current_period_end);
        }
        $dbStatus = in_array($status, ['active', 'trialing'], true) ? 'active' : $status;
        $db->updateUserSubscription((int) $user['id'], $dbStatus, $plan, $periodEnd);
        http_response_code(200);
    }
} elseif ($event->type === 'customer.subscription.deleted') {
    $subscription = $event->data->object;
    $customerId = $subscription->customer;

    $user = $db->findUserByStripeCustomerId($customerId);
    if ($user) {
        $db->updateUserSubscription((int) $user['id'], 'canceled', null, null);
    }
    http_response_code(200);
} else {
    http_response_code(200);
}
