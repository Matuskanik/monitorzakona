<?php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Security;

Security::setSecurityHeaders();
?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Podmienky používania - Monitor zákona</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.7;
            color: #333;
            background: #f5f5f5;
            padding: 20px;
        }
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            margin-bottom: 20px;
        }
        h2 {
            color: #2c3e50;
            margin-top: 30px;
            margin-bottom: 10px;
            font-size: 1.2em;
        }
        p {
            margin-bottom: 12px;
        }
        ul {
            margin-left: 20px;
            margin-bottom: 12px;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #3498db;
            text-decoration: none;
            font-size: 0.9em;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 12px 16px;
            margin: 16px 0;
            color: #856404;
        }
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #ecf0f1;
            color: #95a5a6;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="index.php" class="back-link">← Späť na hlavnú stránku</a>
        <h1>Podmienky používania</h1>

        <p>Vitajte na webovej stránke Monitor zákona. Používaním tejto webovej stránky súhlasíte s týmito Podmienkami používania.</p>

        <h2>1. Prevádzkovateľ</h2>
        <p>Prevádzkovateľom webovej stránky je fyzická osoba Ing. Matúš Kaník (ďalej len „Prevádzkovateľ“).</p>
        <p>Kontakt: <a href="mailto:kanik.matus@gmail.com">kanik.matus@gmail.com</a></p>

        <h2>2. Charakter služby</h2>
        <p>Webová stránka poskytuje automatizované prehľady a zhrnutia verejne dostupných informácií o slovenských zákonoch. Ide o informatívny a edukatívny obsah.</p>

        <div class="warning">
            <strong>Dôležité upozornenie:</strong> Obsah na tejto webovej stránke nepredstavuje právne poradenstvo, právnu službu ani záväzné právne stanovisko. Na právne otázky sa vždy obráťte na kvalifikovaného právnika.
        </div>

        <h2>3. Registrácia a účet</h2>
        <p>Pri registrácii poskytujete minimálne údaje (email a heslo). Zodpovedáte za pravdivosť a aktuálnosť údajov a za bezpečnosť svojho účtu.</p>

        <h2>4. Používanie služby</h2>
        <p>Web môžete používať iba v súlade so zákonmi Slovenskej republiky a týmito podmienkami. Zakazuje sa najmä:</p>
        <ul>
            <li>zneužívať web na nelegálne účely,</li>
            <li>zasahovať do bezpečnosti alebo technickej prevádzky webu,</li>
            <li>automatizovane sťahovať obsah bez súhlasu Prevádzkovateľa.</li>
        </ul>

        <h2>5. Uložené dáta a AI chaty</h2>
        <p>Po prihlásení môžete ukladať zákony a svoje AI chaty. Tieto dáta slúžia na vaše osobné použitie v rámci služby.</p>

        <h2>6. Obmedzenie zodpovednosti</h2>
        <p>Prevádzkovateľ nenesie zodpovednosť za škody, ktoré vzniknú použitím alebo nesprávnym použitím informácií na tejto stránke. Používateľ berie na vedomie, že obsah môže obsahovať nepresnosti alebo neúplnosti.</p>
        <p>Prevádzkovateľ nenesie zodpovednosť za:</p>
        <ul>
            <li>rozhodnutia, ktoré používateľ prijme na základe obsahu stránky,</li>
            <li>priame ani nepriame škody, ušlý zisk alebo iné následné škody,</li>
            <li>dočasnú nedostupnosť služby alebo technické chyby.</li>
        </ul>

        <h2>7. Duševné vlastníctvo</h2>
        <p>Obsah stránky a jej dizajn sú chránené právom duševného vlastníctva. Verejne dostupné zdroje zostávajú majetkom ich pôvodných autorov.</p>

        <h2>8. Zásady ochrany osobných údajov</h2>
        <p>Prevádzkovateľ spracúva osobné údaje v súlade s platnou legislatívou SR a EÚ (najmä GDPR a zákon o ochrane osobných údajov).</p>
        <p><strong>Spracúvané údaje:</strong> emailová adresa, hash hesla, prihlasovacie údaje (napr. Google ID), záznamy o uložených zákonoch a chatoch.</p>
        <p><strong>Účel spracúvania:</strong> vytvorenie a správa používateľského účtu, prihlasovanie, ukladanie obsahu a zabezpečenie prevádzky webu.</p>
        <p><strong>Právny základ:</strong> plnenie zmluvy (poskytovanie služby) a oprávnený záujem Prevádzkovateľa na zabezpečení a zlepšovaní služby.</p>
        <p><strong>Doba uchovávania:</strong> údaje uchovávame po dobu existencie účtu alebo do požiadania o vymazanie, pokiaľ zákon nevyžaduje dlhšie uchovanie.</p>
        <p><strong>Príjemcovia:</strong> údaje neposkytujeme tretím stranám s výnimkou nevyhnutných technických poskytovateľov (napr. hosting) a zákonných povinností.</p>
        <p><strong>Práva používateľa:</strong> máte právo na prístup k údajom, opravu, vymazanie, obmedzenie spracúvania, námietku a prenositeľnosť údajov. Svoje práva môžete uplatniť kontaktom na email uvedený vyššie.</p>

        <h2>9. Zmeny podmienok</h2>
        <p>Prevádzkovateľ si vyhradzuje právo tieto podmienky kedykoľvek upraviť. Aktuálne znenie je vždy dostupné na tejto stránke.</p>

        <h2>10. Rozhodné právo</h2>
        <p>Tieto podmienky sa riadia právom Slovenskej republiky.</p>

        <div class="footer">
            Posledná aktualizácia: <?php echo htmlspecialchars(date('d.m.Y')); ?>
        </div>
    </div>
</body>
</html>
