# V5 Setup - Používateľská databáza a autentifikácia

## Prehľad

V5 pridáva funkčnú databázu používateľov s možnosťou registrácie, prihlásenia a ukladania zákonov a chatov.

## Nové funkcie

1. **Registrácia používateľov** - minimálne údaje (email, heslo, súhlas s podmienkami, captcha)
2. **Prihlásenie** - klasické prihlásenie alebo cez Google OAuth
3. **Moja pamäť** - osobná databáza uložených zákonov a AI chatov
4. **Ukladanie zákonov** - používatelia si môžu ukladať zákony pre neskoršie použitie

## Konfigurácia

Do vášho `.env` súboru pridajte nasledujúce kľúče:

```env
# Google OAuth (voliteľné - ak chcete Google prihlásenie)
GOOGLE_CLIENT_ID=your_google_client_id_here

# Google reCAPTCHA (voliteľné - ak chcete captcha overenie)
RECAPTCHA_SITE_KEY=your_recaptcha_site_key_here
RECAPTCHA_SECRET_KEY=your_recaptcha_secret_key_here
```

### Ako získať Google OAuth credentials:

1. Choďte na [Google Cloud Console](https://console.cloud.google.com/)
2. Vytvorte nový projekt alebo vyberte existujúci
3. Povoľte Google+ API
4. Choďte do "Credentials" → "Create Credentials" → "OAuth client ID"
5. Vyberte "Web application"
6. Pridajte Authorized redirect URIs: `http://localhost/google-callback.php` (pre lokálny vývoj)
7. Skopírujte Client ID do `.env`

### Ako získať reCAPTCHA kľúče:

1. Choďte na [Google reCAPTCHA](https://www.google.com/recaptcha/admin)
2. Vytvorte nový site (reCAPTCHA v2)
3. Pridajte vašu doménu (pre lokálny vývoj použite `localhost`)
4. Skopírujte Site Key a Secret Key do `.env`

**Poznámka:** Ak nechcete používať Google OAuth alebo reCAPTCHA, aplikácia bude fungovať aj bez nich, len tieto funkcie nebudú dostupné.

## Inštalácia závislostí

```bash
composer install
```

Toto nainštaluje Google API klientskú knižnicu pre OAuth funkcionalitu.

## Databázové tabuľky

Aplikácia automaticky vytvorí nasledujúce tabuľky pri prvom spustení:

- `users` - používateľské účty
- `user_saved_laws` - uložené zákony pre každého používateľa
- `user_chats` - AI chaty s megatextami pre každého používateľa

## Použitie

1. **Registrácia:** Používatelia môžu vytvoriť účet na `/register.php`
2. **Prihlásenie:** Prihlásenie je možné na `/login.php`
3. **Moja pamäť:** Po prihlásení je dostupná na `/my-memory.php`
4. **Ukladanie zákonov:** Na stránke zákona (`law.php`) je tlačidlo "Uložiť do Mojej pamäte"

## Bezpečnosť

- Heslá sú hashované pomocou `password_hash()` (bcrypt)
- Session management je zabezpečený
- Všetky vstupy sú validované a sanitizované
- Rate limiting je aplikovaný na všetky požiadavky

## Ďalšie poznámky

- Chat funkcionalita s megatextami bude implementovaná neskôr
- Infraštruktúra pre ukladanie chatov je už pripravená v databáze
- PDF súbor s podmienkami používania bude doplnený neskôr
