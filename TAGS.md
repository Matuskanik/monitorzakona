# Taxónomia tagov a farieb

Tento dokument popisuje systém tagov používaných na kategorizáciu zákonov.

## Prehľad

Každý zákon môže mať 1-5 tagov, ktoré identifikujú oblasti, ku ktorým zákon patrí. Tagy sú generované AI pri analýze zákona a zobrazujú sa nad názvom zákona v farebných odznakoch.

## Formát tagov

- **Dĺžka**: Maximálne 2 slová (ideálne 1 slovo)
- **Jazyk**: Slovenčina
- **Formát**: Malé písmená, s diakritikou alebo bez (konzistentne)
- **Počet**: 1-5 tagov na zákon

## Taxónomia tagov a farieb

### Ekonomika a financie
- **ekonomika** - `#3498db` (Modrá)
- **financie** - `#2ecc71` (Zelená)
- **dane** - `#e74c3c` (Červená)
- **podnikanie** - `#f39c12` (Oranžová)

### Školstvo a vzdelávanie
- **školstvo** - `#9b59b6` (Fialová)
- **vzdelávanie** - `#9b59b6` (Fialová)

### Zdravotníctvo
- **zdravotníctvo** - `#e91e63` (Ružová)
- **zdravie** - `#e91e63` (Ružová)

### Sociálna politika
- **sociálna politika** - `#00bcd4` (Cyan)
- **sociálne** - `#00bcd4` (Cyan)
- **rodina** - `#ff9800` (Tmavá oranžová)
- **mládež** - `#4caf50` (Svetlá zelená)
- **seniori** - `#795548` (Hnedá)

### Doprava a infraštruktúra
- **doprava** - `#607d8b` (Modrošedá)
- **bývanie** - `#8bc34a` (Svetlá zelená)

### Práca a zamestnanie
- **práca** - `#ff5722` (Tmavá oranžová)
- **zamestnanie** - `#ff5722` (Tmavá oranžová)

### Bezpečnosť a právo
- **polícia** - `#3f51b5` (Indigo)
- **justícia** - `#673ab7` (Hluboká fialová)
- **obrana** - `#212121` (Tmavá šedá)

### Životné prostredie
- **životné prostredie** - `#4caf50` (Zelená)
- **ekológia** - `#4caf50` (Zelená)

### Poľnohospodárstvo
- **poľnohospodárstvo** - `#8bc34a` (Svetlá zelená)

### Kultúra a šport
- **kultúra** - `#e91e63` (Ružová)
- **šport** - `#ff9800` (Oranžová)
- **turizmus** - `#00acc1` (Cyan)

### Energetika a IT
- **energetika** - `#ffc107` (Žltá)
- **IT** - `#2196f3` (Svetlá modrá)
- **telekomunikácie** - `#2196f3` (Svetlá modrá)

### Verejná správa
- **verejná správa** - `#795548` (Hnedá)
- **miestna samospráva** - `#607d8b` (Modrošedá)

## Fallback farba

Ak tag nie je v taxonómii, farba sa generuje z hash hodnoty tagu, čím sa zabezpečí konzistentné zobrazenie pre všetky tagy.

## Implementácia

Tagy sú generované AI v metóde `buildPrompt()` v `OpenAIClient.php` a zobrazujú sa v:
- `public/index.php` - zoznam zákonov
- `public/law.php` - detail zákona

Farba tagu sa získava pomocou statickej metódy `OpenAIClient::getTagColor($tag)`.

