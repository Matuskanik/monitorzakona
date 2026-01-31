# Testovanie Stripe bez reálnych platieb (lokálne)

Aby si mohol platiť „na skúšku“ bez reálnych transakcií a faktúr, použi **Stripe Test mode**.

## 1. Prepni Stripe na Test mode

1. Otvor **Stripe Dashboard** (https://dashboard.stripe.com).
2. V **pravom hornom rohu** prepni prepínač z **„Live“** na **„Test“** (pozadie sa zmení na oranžové / test režim).

V Test mode všetky platby sú fiktívne – nič sa neúčtuje, žiadne reálne faktúry.

## 2. Získaj testové kľúče a ceny

- **Developers → API keys:** Skopíruj **Publishable key** (`pk_test_...`) a **Secret key** (`sk_test_...`) – v Test mode sú to test kľúče.
- **Product catalog:** V Test mode vytvor produkt „Monitor zákona Pro“ (alebo použij existujúci test produkt) a dve ceny: **3 €/mesiac** a **30 €/rok**. Skopíruj ich **Price ID** (`price_...`).
- **Developers → Webhooks:** V Test mode pridaj endpoint napr. `http://localhost:8000/stripe-webhook.php` (alebo použiť ngrok pre verejné URL) a skopíruj **Signing secret** (`whsec_...`).

## 3. Lokálne .env pre testovanie

Do `.env` (alebo do kópie `.env.test`) nastav **test** hodnoty (iba keď chceš testovať bez reálnych platieb):

```env
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
STRIPE_PRICE_MONTHLY=price_...   # test mesačná cena
STRIPE_PRICE_YEARLY=price_...   # test ročná cena
APP_BASE_URL=http://localhost:8000
```

Po otestovaní vráť do `.env` **live** kľúče a ceny pre produkciu.

## 4. Testovacie karty (Stripe Test mode)

Pri platbe v Checkout zadaj napr.:

- **Číslo:** `4242 4242 4242 4242`
- **Dátum:** ľubovoľný budúci (napr. 12/34)
- **CVC:** ľubovoľné 3 číslice (napr. 123)
- **ZIP:** ľubovoľný (napr. 12345)

Žiadna reálna platba sa nevykoná. Ďalšie test karty: https://stripe.com/docs/testing#cards

## 5. Webhook pri localhost

Stripe nemôže poslať webhook na `http://localhost:8000` (nie je z internetu viditeľný). Možnosti:

- **A)** Pri lokálnom teste po „platbe“ v Stripe Dashboard (Developers → Events) otvor event a klikni **„Resend“** – ale bez verejnej URL to na tvoj počítač nedorazí. Pre plnohodnotný test webhooku potrebuješ verejné URL (ngrok, expose).
- **B)** Testovať len Checkout (presmerovanie na Stripe, zadanie karty, návrat na success_url). Stav predplatného v aplikácii potom môžeš dočasne nastaviť ručne v DB alebo po nasadení na DO otestovať s live webhookom.

Zhrnutie: **reálne platby = Live mode + live kľúče**. **Skúška bez peňazí = Test mode + test kľúče + test karta 4242...**.
