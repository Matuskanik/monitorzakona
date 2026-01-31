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

$isLoggedIn = $auth->isLoggedIn();
$isPaid = $auth->isPaid();

?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cenník - Monitor zákona</title>
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
            max-width: 700px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header {
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        h1 { color: #2c3e50; font-size: 1.8em; margin-bottom: 12px; }
        .back-link {
            color: #3498db;
            text-decoration: none;
            font-size: 0.9em;
        }
        .back-link:hover { text-decoration: underline; }
        .intro {
            color: #555;
            margin-bottom: 32px;
        }
        .plan-card {
            border: 2px solid #3498db;
            border-radius: 8px;
            padding: 28px;
            margin-bottom: 24px;
        }
        .plan-name { font-size: 1.4em; font-weight: bold; color: #2c3e50; margin-bottom: 16px; }
        .price-row {
            display: flex;
            align-items: baseline;
            gap: 12px;
            margin-bottom: 8px;
        }
        .price-old { text-decoration: line-through; color: #95a5a6; font-size: 1.1em; }
        .price-current { font-size: 1.5em; font-weight: bold; color: #27ae60; }
        .price-note { color: #7f8c8d; font-size: 0.9em; }
        .features {
            margin: 20px 0;
            padding-left: 20px;
        }
        .features li { margin-bottom: 8px; }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            border-radius: 6px;
            font-size: 1em;
            font-weight: bold;
            text-decoration: none;
            text-align: center;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-primary { background: #3498db; color: white; }
        .btn-primary:hover { background: #2980b9; }
        .btn-success { background: #27ae60; color: white; }
        .btn-success:hover { background: #229954; }
        .buttons { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 20px; }
        .footer { margin-top: 40px; padding-top: 20px; border-top: 1px solid #ecf0f1; text-align: center; color: #95a5a6; font-size: 0.9em; }
        .footer a { color: #3498db; text-decoration: none; }
        .alert { padding: 12px; border-radius: 6px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-info { background: #d1ecf1; color: #0c5460; }
        #checkout-error { display: none; color: #c0392b; margin-top: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>Platená verzia</h1>
                <a href="index.php" class="back-link">← Späť na hlavnú stránku</a>
            </div>
            <?php if ($isLoggedIn): ?>
                <a href="logout.php" style="color:#e74c3c; font-size:0.9em;">Odhlásiť sa</a>
            <?php else: ?>
                <a href="login.php?redirect=<?php echo urlencode('pricing.php'); ?>" style="color:#3498db; font-size:0.9em;">Prihlásiť sa</a>
            <?php endif; ?>
        </div>

        <?php if (isset($_GET['success']) && $_GET['success'] === '1'): ?>
            <div class="alert alert-success">Ďakujeme. Vaše predplatné je aktívne.</div>
        <?php endif; ?>
        <?php if (isset($_GET['canceled']) && $_GET['canceled'] === '1'): ?>
            <div class="alert alert-info">Platba bola zrušená. Môžete si kedykoľvek zvoliť platenú verziu nižšie.</div>
        <?php endif; ?>

        <p class="intro">
            Získajte viac možností pre prácu so zákonmi: neobmedzenú konverzáciu s AI, ukladanie zákonov do Mojej pamäte a neobmedzené sťahovanie PDF.
        </p>

        <div class="plan-card">
            <div class="plan-name">Monitor zákona Pro</div>
            <div class="price-row">
                <span class="price-old">6 €/mesiac</span>
                <span class="price-current">3 €/mesiac</span>
                <span class="price-note">(zľava 50 %)</span>
            </div>
            <div class="price-row">
                <span class="price-old">60 €/rok</span>
                <span class="price-current">30 €/rok</span>
                <span class="price-note">(3 €/mesiac, zľava 50 %)</span>
            </div>
            <ul class="features">
                <li>Neobmedzená konverzácia so zákonmi</li>
                <li>Ukladanie zákonov do Mojej pamäte</li>
                <li>Neobmedzené sťahovanie PDF</li>
            </ul>
            <div class="buttons">
                <form method="POST" action="create-checkout-session.php" style="display:inline;">
                    <input type="hidden" name="plan" value="monthly">
                    <button type="submit" class="btn btn-primary">3 €/mesiac</button>
                </form>
                <form method="POST" action="create-checkout-session.php" style="display:inline;">
                    <input type="hidden" name="plan" value="yearly">
                    <button type="submit" class="btn btn-success">30 €/rok – ušetríte 6 €</button>
                </form>
            </div>
            <div id="checkout-error"></div>
        </div>

        <p style="color:#7f8c8d; font-size:0.9em;">Predplatné môžete kedykoľvek zrušiť. Fakturácia cez Stripe.</p>

        <div class="footer" style="margin-top: 40px;">
            <p><a href="index.php">Monitor zákona</a> | <a href="terms.php">Podmienky používania</a></p>
        </div>
    </div>
</body>
</html>
