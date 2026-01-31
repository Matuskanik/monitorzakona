# Presný postup: testovanie Stripe (bezpečne, bez reálnych platieb)

`.env` je nastavený na **Stripe Test mode** – žiadna reálna platba sa nevykoná.

---

## Pred testom

1. **Lokálny server**  
   V priečinku projektu spusti:
   ```bash
   ./bin/start-server.sh
   ```
   Alebo: `php -S localhost:8000 -t public`  
   Server beží na **http://localhost:8000**

2. **Stripe Dashboard v Test mode**  
   Otvor https://dashboard.stripe.com a vpravo hore prepni na **„Test“** (oranžový režim). Všetky operácie sú potom testovacie.

---

## Postup testu (krok za krokom)

### Krok 1: Otvor cenník

- V prehliadači choď na: **http://localhost:8000/pricing.php**

### Krok 2: Prihlás sa

- Ak nie si prihlásený, klikni na „Prihlásiť sa“ a prihlás sa (alebo sa zaregistruj).
- Checkout funguje len pre prihláseného používateľa.

### Krok 3: Spusti „platbu“

- Na stránke cenníka klikni na **„3 €/mesiac“** alebo **„30 €/rok – ušetríte 6 €“**.
- Presmeruje ťa to na **Stripe Checkout** (stránka Stripe).

### Krok 4: Vyplň testovaciu kartu

Na Stripe Checkout zadaj (žiadna reálna platba):

| Pole      | Hodnota              |
|-----------|----------------------|
| Číslo karty | **4242 4242 4242 4242** |
| Dátum     | napr. **12/34** (ľubovoľný budúci) |
| CVC       | napr. **123** (ľubovoľné 3 číslice) |
| Meno      | napr. **Test User**  |
| E-mail    | môže byť tvoj skutočný (nepoužije sa na účtovanie) |

Potom klikni **„Subscribe“** / **„Predplatiť“**.

### Krok 5: Návrat na aplikáciu

- Po úspešnom „zaplatení“ Stripe ťa presmeruje na **http://localhost:8000/pricing.php?success=1**.
- Mal by si vidieť hlášku typu „Ďakujeme. Vaše predplatné je aktívne.“

### Krok 6: Overenie v Stripe (voliteľné)

- V Stripe Dashboard (stále **Test mode**) choď do **Customers** alebo **Subscriptions**.
- Mal by si vidieť nového zákazníka a predplatné – všetko v test režime, žiadna reálna transakcia.

---

## Webhook a stav predplatného v aplikácii

- Stripe **nemôže** poslať webhook na `http://localhost:8000` (localhost nie je z internetu dostupný).
- To znamená: **Checkout a „platba“ prebehnú**, ale udalosť `customer.subscription.created` sa na tvoj počítač nedostane.
- V aplikácii sa preto **nemusí** hneď zmeniť stav predplatného (Moja pamäť, neobmedzený chat atď.). To sa naplno prejaví až na produkcii (Digital Ocean), kde webhook URL je verejná.

Ak chceš otestovať aj aktualizáciu predplatného lokálne:

- Použiť **ngrok** (alebo podobnú službu), zverejni `http://localhost:8000/stripe-webhook.php`, túto URL pridaj v Stripe (Test mode) ako webhook endpoint a do `.env` daj **Signing secret** z tohto testovacieho webhooku (`STRIPE_WEBHOOK_SECRET`).

---

## Po skúške: návrat na produkciu

Keď budeš chcieť znova používať **reálne** platby (napr. na Digital Ocean):

1. Do `.env` vráť **live** hodnoty:
   - `STRIPE_SECRET_KEY=sk_live_...`
   - `STRIPE_PUBLISHABLE_KEY=pk_live_...`
   - `STRIPE_PRICE_MONTHLY=price_1Svg80DX9GTRyFdEm4mpW6wW`
   - `STRIPE_PRICE_YEARLY=price_1Svg8tDX9GTRyFdEvkuXNe1p`
   - `APP_BASE_URL=https://monitorzakona.sk`
2. Na serveri (Digital Ocean) nech sú nastavené **live** premenné, nie test.

---

## Zhrnutie

- **Test mode** = test kľúče + test ceny + karta 4242... → žiadna reálna platba.
- **Lokálne** = Checkout a presmerovanie fungujú; webhook a automatická aktualizácia predplatného v appke až s verejnou URL (produkcia alebo ngrok).
