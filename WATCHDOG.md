# Watchdog - Automatické denné sledovanie zákonov

Watchdog je systém, ktorý automaticky každý deň o 00:00 (polnoc) kontroluje nové zákony na NR SR a spracováva ich.

## Čo watchdog robí

1. **Kontroluje nové zákony** - Každý deň o polnoci načíta aktuálny zoznam zákonov z NR SR
2. **Filtruje nové zákony** - Nájde len tie zákony, ktoré ešte neboli spracované
3. **Spracováva nové zákony** - Automaticky ich stiahne, extrahuje text a vygeneruje AI analýzu
4. **Loguje výsledky** - Všetky aktivity sa zapisujú do `storage/logs/watchdog.log`

## Nastavenie cron jobu

### Automatické nastavenie (odporúčané)

Spustite skript:
```bash
bash bin/setup-cron.sh
```

### Manuálne nastavenie

Ak automatické nastavenie nefunguje, nastavte cron job manuálne:

1. Otvorte crontab editor:
```bash
crontab -e
```

2. Pridajte tento riadok (upravte cestu podľa vašej inštalácie):
```
0 0 * * * cd /Users/apple/aibnb_t/Sentinel && php bin/watchdog.php >> storage/logs/watchdog.log 2>&1
```

3. Uložte a zavrite editor

### Overenie nastavenia

Zobraziť aktuálne cron joby:
```bash
crontab -l
```

Mali by ste vidieť riadok s `watchdog.php`.

## Manuálne spustenie watchdog

Môžete watchdog spustiť manuálne kedykoľvek:

```bash
php bin/watchdog.php
```

## Logy

Watchdog zapisuje logy do:
- `storage/logs/watchdog.log` - špecifické watchdog logy
- `storage/logs/app.log` - všeobecné aplikáčné logy

## Konfigurácia

V `.env` súbore môžete nastaviť:

```env
# Maximálny počet nových zákonov na spracovanie za deň (predvolené: 50)
WATCHDOG_MAX_LAWS=50
```

## Riešenie problémov

### Watchdog sa nespúšťa

1. Skontrolujte, či je cron aktívny:
```bash
crontab -l
```

2. Skontrolujte logy:
```bash
tail -f storage/logs/watchdog.log
```

3. Skontrolujte systémové logy (macOS):
```bash
log show --predicate 'process == "cron"' --last 1h
```

### Watchdog nespracováva zákony

1. Skontrolujte, či máte nastavený `OPENAI_API_KEY` v `.env`
2. Skontrolujte logy pre chybové hlásenia
3. Skúste spustiť watchdog manuálne a pozrite sa na výstup

## Testovanie

Pre testovanie môžete watchdog spustiť manuálne:

```bash
php bin/watchdog.php
```

Alebo môžete dočasne zmeniť čas v cron jobe na napr. každú minútu (len pre testovanie):
```
* * * * * cd /Users/apple/aibnb_t/Sentinel && php bin/watchdog.php >> storage/logs/watchdog.log 2>&1
```

**POZOR:** Nezabudnite po testovaní vrátiť pôvodný čas (0 0 * * *)!

