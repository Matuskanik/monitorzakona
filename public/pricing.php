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
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Cenník - Monitor zákona</title>
    <link rel="stylesheet" href="css/liquid-glass.css">
    <style>.lg-price-row{display:flex;align-items:baseline;gap:12px;margin-bottom:8px;}.lg-price-note{color:var(--text-tertiary);font-size:0.9rem;}#checkout-error{display:none;color:var(--danger);margin-top:12px;}</style>
</head>
<body>
    <div class="lg-container lg-container-mid">
        <div class="lg-header" style="justify-content:space-between;flex-wrap:wrap;gap:16px;">
            <div>
                <h1 class="lg-title">Platená verzia</h1>
                <a href="index.php" class="lg-back-link">← Späť na hlavnú stránku</a>
            </div>
            <?php if ($isLoggedIn): ?>
                <a href="logout.php" class="lg-link lg-nav-danger">Odhlásiť sa</a>
            <?php else: ?>
                <a href="login.php?redirect=<?php echo urlencode('pricing.php'); ?>" class="lg-link">Prihlásiť sa</a>
            <?php endif; ?>
        </div>

        <?php if (isset($_GET['success']) && $_GET['success'] === '1'): ?>
            <div class="lg-info lg-alert-success">Ďakujeme. Vaše predplatné je aktívne.</div>
        <?php endif; ?>
        <?php if (isset($_GET['canceled']) && $_GET['canceled'] === '1'): ?>
            <div class="lg-info">Platba bola zrušená. Môžete si kedykoľvek zvoliť platenú verziu nižšie.</div>
        <?php endif; ?>

        <p class="lg-body" style="margin-bottom:32px;">
            Získajte viac možností pre prácu so zákonmi: neobmedzenú konverzáciu s AI, ukladanie zákonov do Mojej pamäte a neobmedzené sťahovanie PDF.
        </p>

        <div class="lg-plan-card">
            <div class="lg-plan-name">Monitor zákona Pro</div>
            <div class="lg-price-row">
                <span class="lg-price-old">6 €/mesiac</span>
                <span class="lg-price-current">3 €/mesiac</span>
                <span class="lg-price-note">(zľava 50 %)</span>
            </div>
            <div class="lg-price-row">
                <span class="lg-price-old">60 €/rok</span>
                <span class="lg-price-current">30 €/rok</span>
                <span class="lg-price-note">(2,50 €/mesiac, zľava 50 %)</span>
            </div>
            <ul class="lg-features">
                <li>Neobmedzená konverzácia so zákonmi</li>
                <li>Ukladanie zákonov do Mojej pamäte</li>
                <li>Neobmedzené sťahovanie PDF</li>
            </ul>
            <div class="lg-buttons">
                <form method="POST" action="create-checkout-session.php" style="display:inline;">
                    <input type="hidden" name="plan" value="monthly">
                    <button type="submit" class="lg-btn lg-btn-primary">3 €/mesiac</button>
                </form>
                <form method="POST" action="create-checkout-session.php" style="display:inline;">
                    <input type="hidden" name="plan" value="yearly">
                    <button type="submit" class="lg-btn lg-btn-success">30 €/rok – ušetríte 6 €</button>
                </form>
            </div>
            <div id="checkout-error"></div>
        </div>

        <p class="lg-caption" style="margin-top:20px;">Predplatné môžete kedykoľvek zrušiť. Fakturácia cez Stripe.</p>

        <div class="lg-footer" style="margin-top: 40px;">
            <p><a href="index.php">Monitor zákona</a> | <a href="terms.php">Podmienky používania</a></p>
        </div>
    </div>
</body>
</html>
