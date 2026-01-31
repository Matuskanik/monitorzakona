<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Security;

try {
    Config::load();
} catch (\Exception $e) {
    // Ignore config errors for this page
}

Security::setSecurityHeaders();

// Rate limiting
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!Security::checkRateLimit($ip, 60, 60)) {
    http_response_code(429);
    die("Príliš veľa požiadaviek. Skúste znova neskôr.");
}

// System prompt
$systemPrompt = 'Si expertný právny analytik a komunikátor, ktorý špecializuje sa na analýzu slovenských zákonov a ich preklad do zrozumiteľného jazyka pre bežných občanov. Tvoja úloha je poskytnúť hĺbkovú, praktickú a užitočnú analýzu, ktorá ľuďom pomôže pochopiť, ako ich zákon ovplyvní a čo môžu konkrétne urobiť. Vždy odpovedáš v JSON formáte podľa presnej schémy, pričom každá sekcia musí byť detailná, konkrétna a prakticky použiteľná.';

// User prompt template
$userPrompt = 'Analyzuj nasledujúci text zo slovenského zákona a vytvor podrobný, kvalitný JSON objekt s týmito presnými kľúčmi:

{
  "summary_paragraph": "Detailné, viacodsekové zhrnutie zákona (minimálne 3-5 viet, ideálne 150-300 slov). Vysvetli: čo zákon mení, prečo to môže byť dôležité, aké sú kľúčové body, ktoré by ľudia mali vedieť. Používaj konkrétne príklady a situácie, kde je to možné. Píš živým, zrozumiteľným jazykom, ale zachovávaj presnosť.",
  "affected_groups": ["konkrétna skupina 1 s vysvetlením ako ich to ovplyvní", "konkrétna skupina 2 s vysvetlením ako ich to ovplyvní"],
  "positives": ["konkrétne pozitívum 1 s vysvetlením prečo je to pozitívne", "konkrétne pozitívum 2 s vysvetlením"],
  "negatives": ["konkrétne negatívum 1 s vysvetlením akých dôsledkov sa to týka", "konkrétne negatívum 2 s vysvetlením"],
  "how_to_react": ["konkrétna, akčná rada 1 - čo presne má človek urobiť, kedy, ako", "konkrétna, akčná rada 2 - krok za krokom", "konkrétna, akčná rada 3 - praktický tip"],
  "disclaimer": "Toto nie je právne poradenstvo. Informácie sú len informatívneho charakteru. Pre právne poradenstvo sa obráťte na kvalifikovaného právnika."
}

DETALNÉ INŠTRUKCIE PRE KAŽDÚ SEKCIU:

**summary_paragraph:**
- Napíš podrobné, viacodsekové zhrnutie (minimálne 150 slov, ideálne 200-300 slov)
- Začni kontextom: čo sa mení a prečo
- Vysvetli kľúčové zmeny konkrétne, nie abstraktne
- Uveď príklady situácií, kde je to relevantné
- Spomeň časové aspekty (kedy to nadobudne platnosť, aké sú prechodné obdobia)
- Ak sú dôležité čísla, sumy, limity - uveď ich
- Používaj živý, zrozumiteľný jazyk, ale buď presný

**affected_groups:**
- Identifikuj konkrétne skupiny ľudí (nie len "občania", ale napr. "zamestnanci v malých firmách do 10 zamestnancov", "študenti vysokých škôl", "dôchodcovia nad 65 rokov")
- Pre každú skupinu stručne vysvetli, ako ich to konkrétne ovplyvní (nie len "sú ovplyvnení", ale "budú musieť...", "stratia možnosť...", "získajú právo...")
- Minimálne 3-5 konkrétnych skupín, ak je to možné

**positives:**
- Identifikuj skutočné výhody a pozitíva, nie len formálne
- Pre každé pozitívum vysvetli, prečo je to výhoda a pre koho konkrétne
- Ak sú to len teoretické výhody, ktoré v praxi nefungujú, spomeň to
- Buď realistický - nie každý zákon má len pozitíva

**negatives:**
- Identifikuj skutočné problémy, riziká a nevýhody
- Pre každé negatívum vysvetli, aké konkrétne dôsledky to môže mať
- Spomeň, kto konkrétne môže byť negatívne ovplyvnený
- Buď konštruktívny, ale aj uprimný

**how_to_react:**
- Toto je NAJDÔLEŽITEJŠIA sekcia - musí obsahovať konkrétne, akčné rady
- Každá rada musí byť praktická a použiteľná (nie len "prečítajte si zákon")
- Uveď konkrétne kroky: čo presne má človek urobiť, kedy, kde, ako
- Ak sú lehoty, termíny - uveď ich
- Ak sú potrebné dokumenty, formuláre - spomeň ich
- Ak sú kontakty, inštitúcie - uveď ich
- Ak sú možnosti odvolania, námietok - vysvetli proces
- Minimálne 3-5 konkrétnych, akčných rád
- Príklady dobrých rád: "Do 30 dní od nadobudnutia platnosti zákona pošlite žiadosť na [inštitúcia] na adresu [adresa] s prílohou [dokumenty]. Formulár nájdete na [URL]."
- Príklady zlých rád (NEPOUŽÍVAŤ): "Prečítajte si zákon", "Kontaktujte právnika", "Buďte opatrní"

VŠEOBECNÉ PRAVIDLÁ:
- Používaj LEN informácie z poskytnutého textu. Ak niečo nie je v texte, napíš "Informácie o tomto aspekte nie sú v poskytnutom texte dostupné."
- Buď zrozumiteľný pre bežných ľudí bez právnického vzdelania
- Vyhýbaj sa halucináciám. Ak si nie si istý, povedz to
- Používaj opatrnú reč tam, kde je to vhodné ("môže ovplyvniť", "pravdepodobne", "podľa textu zákona")
- Pre "how_to_react": LEN legálne, súladné, praktické návrhy. Žiadne nelegálne rady, daňové úniky alebo "exploity"
- Všetky texty musia byť v slovenčine
- Odpovedaj VÝLUČNE v JSON formáte bez akýchkoľvek dodatočných komentárov
- Kvalita je dôležitejšia ako kvantita - lepšie menej, ale kvalitných bodov, než veľa povrchných

Text zákona:
[Tu sa vkladá text zákona - obmedzený na 30000 znakov]';

?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="favicon.png">
    <title>Použité prompty - Monitor zákona</title>
    <link rel="stylesheet" href="css/liquid-glass.css">
</head>
<body>
    <div class="lg-container lg-container-wide">
        <a href="index.php" class="lg-back-link">← Späť na zoznam</a>
        
        <h1 class="lg-title" style="margin-bottom:28px;border-bottom:3px solid var(--accent);padding-bottom:12px;">Použité prompty</h1>
        
        <div class="lg-prompt-section">
            <div class="lg-prompt-title">System Prompt (Systémový prompt)</div>
            <div class="lg-prompt-content"><?php echo htmlspecialchars($systemPrompt); ?></div>
            <div class="lg-prompt-meta">Role: system | Používa sa na definovanie identity a správania AI asistenta</div>
        </div>

        <div class="lg-prompt-section">
            <div class="lg-prompt-title">User Prompt (Užívateľský prompt)</div>
            <div class="lg-prompt-content"><?php echo htmlspecialchars($userPrompt); ?></div>
            <div class="lg-prompt-meta">Role: user | Používa sa na každú analýzu zákona. Text zákona sa vkladá na koniec tohto promptu.</div>
        </div>

        <div class="lg-prompt-section">
            <div class="lg-prompt-title">Technické parametre</div>
            <div class="lg-prompt-content">Temperature: 0.2
Max Tokens: 2000 (alebo hodnota z konfigurácie)
Response Format: JSON Object
Model: gpt-4o-mini (alebo hodnota z konfigurácie)
Limit textu zákona: 30000 znakov</div>
        </div>

        <div class="lg-footer">
            <p>Automaticky monitorované z <a href="https://www.nrsr.sk/web/default.aspx?SectionId=184" target="_blank">NR SR</a></p>
            <p style="margin-top: 10px;">
                <a href="prompts.php">Použité prompty</a> | <a href="terms.php">Podmienky používania</a> | Autor: Matúš Kaník
            </p>
        </div>
    </div>
</body>
</html>

