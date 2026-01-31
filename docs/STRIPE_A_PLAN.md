# Plán: Stripe platby a platená verzia (v7)

## 1. Prehľad úrovní

| Funkcia | Bezplatná verzia | Platená verzia |
|--------|-------------------|----------------|
| Prezeranie analýz zákonov | ✅ | ✅ |
| AI chat – otázky k zákonu | **1 otázka na jeden zákon** (celkovo) | Neobmedzená konverzácia (s vnútorným limitom) |
| Ukladanie zákonov do Mojej pamäte | ❌ | ✅ |
| Generovanie a sťahovanie PDF | **1×** na účet; po ďalšom kliku → presmerovanie na cenník | Neobmedzené sťahovanie |
| Cena | 0 € | 3 €/mesiac alebo 30 €/rok (zľava 50 %) |

**Skutočné ceny v Stripe:** 6 €/mesiac, 60 €/rok – zľava 50 % sa prezentuje ako preškrtnutá pôvodná cena (6 € → 3 €, 60 € → 30 €).

---

## 2. Odporúčané umiestnenia Stripe tlačidla

### A) Hlavná stránka (index.php) – prihlásený používateľ (free)
- **Kde:** Vedľa „Moja pamäť“ a „Odhlásiť sa“ v hlavičke.
- **Čo:** Jedno tlačidlo typu „Upgradovať na platenú verziu“ (primárny CTA).
- **Prečo:** Viditeľné hneď po prihlásení, bez nutnosti ísť na zákon.

### B) Stránka zákona (law.php) – chat
- **Kde:** Nad alebo pod blokom „Opýtajte sa zákona“, ak je používateľ free a už použil 1 otázku na tento zákon.
- **Čo:** Náhrada / doplnenie formulára: „Na ďalšie otázky aktivujte platenú verziu“ + Stripe tlačidlo (alebo odkaz na cenník).
- **Prečo:** Presne v momente, keď používateľ narazí na limit.

### C) Stránka zákona (law.php) – tlačidlo „Stiahnuť PDF“
- **Správanie:** Ak je free a už sťahoval PDF 1×, pri kliku na „Stiahnuť PDF“ **nepustíme** do law-pdf.php, ale presmerujeme na **podstránku cenníka** (napr. `pricing.php` alebo `platená-verzia`).
- **Voliteľne:** Vedľa tlačidla malý text: „Bezplatní používatelia: 1× sťahovanie. Ďalšie po upgrade.“

### D) Moja pamäť (my-memory.php)
- **Kde:** Ak používateľ nie je platený – namiesto zoznamu uložených zákonov zobraziť **upsell**: „Ukladanie zákonov do Mojej pamäte je súčasťou platenej verzie“ + Stripe tlačidlo / odkaz na cenník.
- **Prečo:** Jednoznačne spája funkciu s platením.

### E) Podstránka cenníka (pricing / platená verzia)
- **Kde:** Nová stránka napr. `public/pricing.php` (URL napr. `/pricing.php` alebo `/platená-verzia`).
- **Čo:** Hlavné miesto pre predstavenie plateného plánu a **dve Stripe tlačidlá**: mesačný plán (3 €/mesiac) a ročný plán (30 €/rok).
- **Odkazy na túto stránku:** Z hlavičky („Cenník“), z law.php (keď limit chat/PDF), z my-memory.php (upsell).

---

## 3. Podstránka cenníka – návrh (štýl SaaS)

### URL
- `pricing.php` (alebo `cenovy-plan.php` / `platená-verzia.php` podľa preferencie).

### Štruktúra stránky
1. **Nadpis:** napr. „Platená verzia – viac možností pre prácu so zákonmi“.
2. **Krátky úvod:** 2–3 vety, prečo platená verzia (neobmedzený chat, Moja pamäť, PDF bez limitu).
3. **Jedna karta plánu** (môže byť neskôr rozšírené na viac plánov):
   - **Názov:** napr. „Monitor zákona Pro“.
   - **Cenník:**
     - Mesačná platba: ~~6 €~~ **3 €/mesiac** (zľava 50 %).
     - Ročná platba: ~~60 €~~ **30 €/rok** (3 €/mesiac, zľava 50 %).
   - **Zoznam výhod:**  
     Neobmedzená konverzácia so zákonmi, Ukladanie zákonov do Mojej pamäte, Neobmedzené sťahovanie PDF.
   - **Dve tlačidlá:** „3 €/mesiac“ (Stripe Checkout – monthly) a „30 €/rok – ušetríte 6 €“ (Stripe Checkout – yearly).
4. **FAQ (voliteľne):** Zrušenie kedykoľvek, fakturácia, čo sa stane po zrušení.

### Vizuál
- Jednoduchý, čistý layout (ako zvyšok aplikácie).
- Preškrtnuté pôvodné ceny (6 €, 60 €), zvýraznené aktuálne ceny (3 €, 30 €).
- Stripe tlačidlá môžu byť buď oficiálne Stripe Checkout (presmerovanie na Stripe), alebo vlastné tlačidlá, ktoré volajú Stripe (napr. Payment Element alebo Checkout Session).

---

## 4. Zmeny v databáze

### Tabuľka `users` – pridať stĺpce
- `stripe_customer_id` (TEXT, nullable) – pre viazanie predplatného na zákazníka.
- `subscription_status` (TEXT, default `'free'`) – hodnoty: `free`, `active`, `past_due`, `canceled`, `trialing`.
- `subscription_plan` (TEXT, nullable) – napr. `monthly`, `yearly`.
- `subscription_current_period_end` (TEXT, nullable) – ISO dátum konca obdobia (pre kontrolu platnosti).

### Nová tabuľka `user_pdf_downloads`
- `id` (INTEGER PRIMARY KEY).
- `user_id` (INTEGER, FK users).
- `downloaded_at` (TEXT, DEFAULT CURRENT_TIMESTAMP).
- Účel: počítať počet sťahovaní PDF na používateľa (free = max 1).

### Limity pre „neobmedzený“ chat (platený)
- **Možnosť 1 (odporúčaná):** Max **X správy na zákon za mesiac** (napr. 100 alebo 200). Nad limitom zobraziť: „Mesačný limit konverzácie pre tento zákon ste vyčerpali. Skúste znova ďalší mesiac.“
- **Možnosť 2:** Max **Y správ celkom za mesiac** (všetky zákony dokopy), napr. 500.
- Hodnoty X/Y nastaviť v konfigurácii (`.env`), aby sa dali meniť bez zásahu do kódu.

---

## 5. Backend logika (stručne)

### Autentifikácia
- Všade, kde treba vedieť „je platený?“: helper napr. `Subscription::isActive($userId)` alebo rozšírenie `Auth` o `isPaid()`.
- Platený = `subscription_status === 'active'` (prípadne `trialing`) a `subscription_current_period_end` v budúcnosti.

### Chat (law-chat.php)
- **Free:** Pred spracovaním otázky overiť: pre daného `user_id` + `law_id` počet **uložených** výmen (user_chats.messages_json) – ak už existuje aspoň 1 pár user+assistant (t.j. jedna „otázka“), vrátiť chybu: „Na ďalšie otázky k tomuto zákonu aktivujte platenú verziu“ a HTTP 403.
- **Platený:** Povoliť ďalšie otázky; na backendu navyše kontrolovať mesačný limit (X alebo Y podľa zvolenej možnosti). Pri prekročení vrátiť 403 s jasnou hláškou.

### PDF (law-pdf.php)
- Overiť prihlásenie (ak ešte nie je).
- **Free:** Počet záznamov v `user_pdf_downloads` pre `user_id`: ak ≥ 1, **nerenderovať PDF**, vrátiť napr. JSON `{ "limit_reached": true, "redirect": "/pricing.php" }` s HTTP 403. Frontend (law.php) pri tejto odpovedi presmeruje na `pricing.php`.
- **Platený:** Povoliť sťahovanie; pri každom úspešnom vygenerovaní PDF pridať záznam do `user_pdf_downloads` (pre štatistiky; pre platených limit nekontrolujeme).

### Moja pamäť (my-memory.php)
- Ak používateľ nie je platený: nezobraziť zoznam uložených zákonov, zobraziť upsell blok (text + odkaz na `pricing.php` / Stripe).
- Ukladanie do Mojej pamäte (save law) – na law.php: ak nie je platený, tlačidlo „Uložiť do Mojej pamäte“ buď skryté, alebo pri kliku presmerovať na cenník.

### Stripe
- **Checkout Session:** Pri „Upgradovať“ / výbere plánu na `pricing.php` vytvoriť Stripe Checkout Session (mode: subscription), success_url / cancel_url spätne na tvoju doménu.
- **Webhook:** Stripe endpoint (napr. `public/stripe-webhook.php`) na udalosti `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`. V webhooku aktualizovať `users.subscription_*` podľa `customer` (nájdenie podľa `stripe_customer_id` alebo email).
- Pri prvom platobnom toku vytvoriť Stripe Customer (ak ešte neexistuje) a uložiť `stripe_customer_id` do `users`.

---

## 6. Čo od teba potrebujem (vstupy)

Aby sa to dalo bezchybne spájať so Stripe a prostredím, potrebujem od teba tieto údaje a rozhodnutia:

### 6.1 Stripe (povinné)
1. **Stripe Secret Key** (začína `sk_live_` pre produkciu, `sk_test_` pre testovanie).  
   - Kde: Stripe Dashboard → Developers → API keys.  
   - Použitie: backend (vytvorenie Checkout Session, vytvorenie Customera, overenie webhooku).

2. **Stripe Publishable Key** (začína `pk_live_` resp. `pk_test_`).  
   - Kde: ten istý Dashboard.  
   - Použitie: frontend (voliteľne, ak budeš chcieť napr. Payment Element; pri čistom Checkout redirect nie je nutný na frontende, ale môžeme ho mať v .env).

3. **Stripe Webhook Signing Secret** (začína `whsec_`).  
   - Kde: Stripe Dashboard → Developers → Webhooks → Add endpoint → URL tvojho `stripe-webhook.php` → po vytvorení zobraziť „Signing secret“.  
   - Použitie: overenie, že webhook skutočne prišiel od Stripe.

4. **ID cenových plánov (Price IDs) v Stripe:**  
   - Mesačný: 3 €/mesiac – potrebujem **Price ID** (začína `price_`).  
   - Ročný: 30 €/rok – potrebujem **Price ID** (začína `price_`).  
   - Kde: Stripe Dashboard → Products → vytvor produkt „Monitor zákona Pro“ (alebo názov podľa teba) → pridaj dve ceny (recurring): 3 €/mesiac a 30 €/rok → skopíruj Price IDs.

### 6.2 Aplikácia a URL
5. **Základná URL aplikácie** (napr. `https://tvojadomena.sk` alebo `https://monitorzakona.sk`).  
   - Použitie: success_url a cancel_url pre Stripe Checkout, odkaz na cenník v mailoch (ak budeš posielať).

6. **Potvrdenie názvu podstránky cenníka:**  
   - Napr. `pricing.php` → `/pricing.php`, alebo `cenovy-plan.php` → `/cenovy-plan.php`.  
   - Podľa toho nastavím všetky presmerovania a odkazy.

### 6.3 Obmedzenia (voliteľné, môžem navrhnúť defaulty)
7. **Mesačný limit správ v chate pre platených** (ak chceš „neobmedzené, ale s istým stropom“):  
   - Napr. 100 alebo 200 správ na zákon za mesiac, alebo 500 správ celkom za mesiac.  
   - Ak nechceš riešiť, môžem nastaviť rozumný default (napr. 200 na zákon/mesiac).

8. **Mena v Stripe:**  
   - Predpokladám EUR (€). Ak bude iná mena, napíš (napr. CZK).

---

## 7. Súhrn krokov implementácie (na mojej strane)

1. Rozšíriť **Database.php**: migrácia pre `users` (stĺpce Stripe + subscription) a tabuľka `user_pdf_downloads`.
2. Pridať **Subscription helper** (alebo metódy do Auth): kontrola `isPaid()`, naplnenie z DB.
3. Upraviť **law-chat.php**: kontrola 1 otázky pre free; pre platených kontrola mesačného limitu (podľa bodu 4 a 6.3).
4. Upraviť **law-pdf.php**: kontrola 1× sťahovania pre free, pri limit vrátiť redirect na cenník; pre platených pridať záznam do `user_pdf_downloads`.
5. Upraviť **law.php** (frontend): pri odpovedi „limit_reached“ z PDF presmerovať na `pricing.php`; pri free a po 1. otázke zobraziť CTA na platenú verziu.
6. Upraviť **my-memory.php**: pre free zobraziť upsell, pre platených súčasnú funkcionalitu.
7. Upraviť **index.php**: pre prihláseného free používateľa pridať tlačidlo „Upgradovať“ / „Cenník“.
8. Vytvoriť **pricing.php**: text, cenník (preškrtnuté 6 €/60 €, zľava 50 %), dve Stripe tlačidlá (mesačný / ročný).
9. Implementovať **Stripe Checkout**: endpoint na vytvorenie Checkout Session (napr. `create-checkout-session.php` alebo v `pricing.php` POST), nastaviť success/cancel URL.
10. Implementovať **stripe-webhook.php**: spracovanie subscription udalostí a aktualizácia `users.subscription_*` a `stripe_customer_id`.
11. Pridať do **.env** (a Config): `STRIPE_SECRET_KEY`, `STRIPE_PUBLISHABLE_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRICE_MONTHLY`, `STRIPE_PRICE_YEARLY`, `APP_BASE_URL`; voliteľne `CHAT_MESSAGES_PER_LAW_PER_MONTH` alebo podobne.

---

Keď mi dodáš údaje z bodov **6.1–6.2** (a prípadne 6.3–6.4), môžem pripraviť konkrétnu implementáciu (kód) podľa tohto plánu. Nič z toho nebudem committovať, kým nepovieš.
