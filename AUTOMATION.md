# Automatizácia a spracovanie zákonov

## Spracovanie zákonov za rok 2025

Pre spracovanie všetkých zákonov za rok 2025 spustite:

```bash
php bin/process-year-2025.php
```

Tento skript:
- Načíta všetky zákony z NR SR
- Filtruje len zákony z roku 2025 (podľa dátumu doručenia)
- Spracuje nové zákony (preskočí už spracované)
- Extrahuje dátumy, texty a generuje AI analýzy s tagmi

## Watchdog - Automatické denné sledovanie

Watchdog systém je nastavený na automatické spustenie každý deň o 00:00 (polnoc).

### Čo watchdog robí:

1. **Kontroluje nové zákony** - Každý deň o polnoci načíta aktuálny zoznam zákonov z NR SR
2. **Filtruje nové zákony** - Nájde len tie zákony, ktoré ešte neboli spracované
3. **Spracováva nové zákony** - Automaticky ich stiahne, extrahuje text a vygeneruje AI analýzu s tagmi
4. **Loguje výsledky** - Všetky aktivity sa zapisujú do `storage/logs/watchdog.log`

### Stav watchdogu:

Watchdog je **aktívny** a beží každý deň o 00:00.

### Manuálne spustenie watchdog:

Môžete watchdog spustiť manuálne kedykoľvek:

```bash
php bin/watchdog.php
```

### Logy:

Watchdog zapisuje logy do:
- `storage/logs/watchdog.log` - špecifické watchdog logy
- `storage/logs/app.log` - všeobecné aplikáčné logy

### Kontrola watchdogu:

Zobraziť aktuálne cron joby:
```bash
crontab -l
```

Skontrolovať logy:
```bash
tail -f storage/logs/watchdog.log
```

### Konfigurácia:

V `.env` súbore môžete nastaviť:

```env
# Maximálny počet nových zákonov na spracovanie za deň (predvolené: 50)
WATCHDOG_MAX_LAWS=50
```

### Riešenie problémov:

**Watchdog sa nespúšťa:**
1. Skontrolujte cron: `crontab -l`
2. Skontrolujte logy: `tail -f storage/logs/watchdog.log`
3. Skontrolujte systémové logy (macOS): `log show --predicate 'process == "cron"' --last 1h`

**Watchdog nespracováva zákony:**
1. Skontrolujte, či máte nastavený `OPENAI_API_KEY` v `.env`
2. Skontrolujte logy pre chybové hlásenia
3. Skúste spustiť watchdog manuálne a pozrite sa na výstup

## Ďalšie užitočné skripty:

- `bin/process-recent.php [počet]` - Spracuje posledných N zákonov
- `bin/reprocess-law.php [id]` - Prepracuje konkrétny zákon
- `bin/reprocess-all.php` - Prepracuje všetky zákony
- `bin/update-dates.php` - Aktualizuje dátumy doručenia pre existujúce zákony

