<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\Auth;

try {
    Config::load();
} catch (\Exception $e) {
    die("Configuration error: " . htmlspecialchars($e->getMessage()));
}

$db = new Database(Config::get('DB_PATH'));
$auth = new Auth($db);

$auth->logout();

header('Location: index.php');
exit;
