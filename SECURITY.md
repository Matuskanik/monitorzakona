# Bezpečnostná analýza a zlepšenia

## ✅ Čo je už zabezpečené

### 1. API kľúč
- ✅ API kľúč je uložený v `.env` súbore
- ✅ `.env` súbor je v `.gitignore` - nie je v git repozitári
- ✅ API kľúč sa používa len server-side, nie je vystavený klientovi

### 2. SQL Injection
- ✅ Všetky SQL dotazy používajú prepared statements
- ✅ Používa sa PDO s parametrami (`?` placeholders)
- ✅ Žiadne priame vloženie používateľských dát do SQL

### 3. XSS (Cross-Site Scripting)
- ✅ Všetky výstupy používajú `htmlspecialchars()`
- ✅ Používa sa UTF-8 encoding
- ✅ Bezpečné zobrazenie dát z databázy

## 🔒 Implementované zlepšenia

### 1. Security Headers
Pridané security headers v `.htaccess` a PHP kóde:
- `X-XSS-Protection` - ochrana pred XSS útokmi
- `X-Content-Type-Options: nosniff` - zabránenie MIME type sniffing
- `X-Frame-Options: SAMEORIGIN` - ochrana pred clickjacking
- `Referrer-Policy` - kontrola referrer informácií
- `Content-Security-Policy` - obmedzenie zdrojov
- `Permissions-Policy` - kontrola prístupu k API

### 2. Validácia vstupov
- ✅ `lawId` je teraz validovaný ako integer
- ✅ Search query je sanitizovaný a obmedzený na 200 znakov
- ✅ Ochrana pred neplatnými dátami

### 3. Rate Limiting
- ✅ Jednoduchý rate limiting pomocou session
- ✅ Predvolené: 60 requestov za 60 sekúnd
- ✅ Ochrana pred automatizovanými útokmi

### 4. Bot Detection
- ✅ Detekcia základných botov podľa User-Agent
- ✅ Identifikácia crawlerov a scraperov

### 5. Blokovanie citlivých súborov
- ✅ `.env`, `.git`, `composer.json` sú blokované v `.htaccess`

## 📋 Odporúčania pre ďalšie zlepšenia

### 1. Cloudflare (ak používate)
- Zapnite Cloudflare WAF (Web Application Firewall)
- Zapnite DDoS protection
- Zapnite Bot Fight Mode alebo Super Bot Fight Mode
- Nastavte rate limiting rules v Cloudflare dashboard

### 2. Monitoring
- Nastavte logovanie podezrivých aktivít
- Monitorujte rate limiting blokácie
- Sledujte neobvyklé requesty

### 3. HTTPS
- Uistite sa, že používate HTTPS
- Nastavte HSTS header (ak používate Cloudflare, je automaticky)

### 4. Databáza
- Uistite sa, že databázový súbor nie je prístupný cez web
- Pravidelné zálohovanie
- Kontrola oprávnení súborov (644 pre databázu)

### 5. PHP konfigurácia
- `display_errors = Off` v produkcii
- `expose_php = Off`
- `allow_url_fopen = Off` (ak nie je potrebné)

## 🛡️ Ochrana proti automatizovaným útokom

### Implementované:
1. ✅ Rate limiting - obmedzuje počet requestov
2. ✅ Bot detection - identifikuje základné boty
3. ✅ Security headers - komplikuje automatizáciu
4. ✅ Input validation - zabraňuje neplatným dátam

### Cloudflare (odporúčané):
- Bot Fight Mode - automatická ochrana proti botom
- Rate Limiting Rules - pokročilejšie rate limiting
- WAF Rules - ochrana proti známym útokom
- DDoS Protection - ochrana proti DDoS útokom

## 🔍 Testovanie bezpečnosti

### 1. Skontrolujte security headers
```bash
curl -I https://vasadomena.sk
```

### 2. Test rate limiting
- Skúste urobiť viac ako 60 requestov za minútu
- Mala by sa zobraziť chybová správa

### 3. Test validácie vstupov
- Skúste `law.php?id=abc` - mala by sa zobraziť chyba
- Skúste `law.php?id=-1` - mala by sa zobraziť chyba
- Skúste `law.php?id=999999` - mala by sa zobraziť chyba ak neexistuje

## 📝 Zmeny v kóde

### Nové súbory:
- `app/Security.php` - Security helper trieda
- `SECURITY.md` - táto dokumentácia

### Upravené súbory:
- `public/.htaccess` - pridané security headers
- `public/law.php` - pridaná validácia vstupov
- `public/index.php` - pridaná validácia search query

## ⚠️ Dôležité poznámky

1. **API kľúč je bezpečný** - je v `.env` súbore, ktorý nie je v git repozitári
2. **Rate limiting používa session** - vyžaduje session support na serveri
3. **Security headers** - niektoré môžu byť prepísané Cloudflare (ak používate)
4. **Bot detection** - je základná, pre lepšiu ochranu použite Cloudflare Bot Fight Mode

## 🚀 Ďalšie kroky

1. ✅ Implementované základné bezpečnostné zlepšenia
2. ⏭️ Nastavte Cloudflare (ak ešte nie je)
3. ⏭️ Zapnite monitoring a logovanie
4. ⏭️ Pravidelne kontrolujte security headers
5. ⏭️ Aktualizujte PHP a závislosti
