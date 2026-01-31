# Liquid Glass dizajn – Monitor zákona

Dizajnový systém inšpirovaný **Apple Liquid Glass** (iOS 26 / macOS Tahoe): priehľadné sklo, blur, zaoblené tvary, jemná typografia. Cieľová skupina: občania a profesionáli hľadajúci prehľad slovenských zákonov – dôveryhodný, čistý a prístupný vzhľad.

## Aplikované princípy

- **Sklo (glass):** `backdrop-filter: blur(20px)`, polopriehľadné pozadie (`rgba`), jemné okraje a tiene.
- **Zaoblenia:** väčšie radius (14–24px) pre karty a panely.
- **Typografia:** systémové písmo (-apple-system), čitateľné veľkosti a line-height.
- **Farby:** akcent modrá (#0071e3 / #0a84ff v dark mode), zelená pre úspech, červená pre chyby; tmavý režim cez CSS premenné.
- **Jednotnosť:** jeden súbor `public/css/liquid-glass.css` a triedy s prefixom `lg-*` na všetkých stránkach.

## Súbory

| Súbor | Popis |
|-------|--------|
| `public/css/liquid-glass.css` | Hlavný štýl – premenné, base, karty, formuláre, tlačidlá, dark mode, footer, chat, prompts. |
| Všetky `public/*.html` a `public/*.php` | Odkazujú na `css/liquid-glass.css` a používajú triedy `lg-*`. |

## Stránky s Liquid Glass

- **index.html, index.php** – hlavná stránka, vyhľadávanie, zoznam zákonov, dark mode toggle.
- **login.php, register.php** – prihlásenie / registrácia, formuláre, Google / reCAPTCHA.
- **pricing.php** – cenník, plán, tlačidlá Stripe.
- **terms.php** – podmienky, disclaimer.
- **law.php** – detail zákona, sekcie, chat, ukladanie do pamäte, dark mode.
- **my-memory.php** – uložené zákony a AI chaty.
- **prompts.html, prompts.php** – použité prompty.

## Dark mode

- Uložený v `localStorage` pod kľúčom `darkMode` (1 = zapnutý).
- Prepínač v ľavom hornom rohu (OFF/ON) na index.html, index.php, law.php.
- CSS premenné v `:root` a `html.dark-mode` menia pozadie, text, sklo a akcenty.

## Ďalšie úpravy

- Nové stránky: pripojiť `css/liquid-glass.css` a používať triedy `lg-container`, `lg-btn`, `lg-card` atď.
- Zmena akcentu: upraviť `--accent` a `--accent-hover` v `:root` a `html.dark-mode` v `liquid-glass.css`.
- Ďalšie „sklené“ panely: použiť `.lg-card` alebo vlastný prvok s `background: var(--glass-bg)`, `backdrop-filter: blur(var(--glass-blur))`, `border-radius: var(--radius-lg)`.
