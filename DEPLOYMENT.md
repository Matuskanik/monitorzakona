# Deployment Guide - $0 Tech Stack

## Digital Ocean (monitorzakona.sk)

- **Aktuálna produkcia (v7):** nasadzuje sa z vetvy **v7** – Stripe platby, platená verzia (3 €/mes, 30 €/rok), free limity (1 otázka/zákon, 1× PDF).
- Predchádzajúce: **v3** / **V5** – Google prihlásenie, Moja pamäť.
- **Nasadenie v7 na DO:**
  1. Digital Ocean App Platform → váš projekt → **Settings** → **App** → **Source**.
  2. Nastavte **Branch** na **v7**.
  3. V **Settings** → **App-Level Environment Variables** skontrolujte/priďte:
     - **GOOGLE_CLIENT_ID** (prihlásenie cez Google)
     - **STRIPE_SECRET_KEY** (sk_live_...), **STRIPE_PUBLISHABLE_KEY** (pk_live_...)
     - **STRIPE_WEBHOOK_SECRET** (whsec_...), **STRIPE_PRICE_MONTHLY**, **STRIPE_PRICE_YEARLY**
     - **APP_BASE_URL** = `https://monitorzakona.sk`
  4. Spustite **Deploy** (alebo nechajte automatický deploy po push na v7).
- Po nasadení skontrolujte: https://monitorzakona.sk/login.php, https://monitorzakona.sk/pricing.php.
- **Stripe webhook:** V Stripe Dashboard (Live) musí byť endpoint **https://monitorzakona.sk/stripe-webhook.php** s eventmi `customer.subscription.created/updated/deleted`. Po pridaní/zmene env **spustite nový deploy**.

---

This application has been optimized to run on a **$0 tech stack** using:
- **GitHub Actions** for automated processing (free)
- **Cloudflare Pages** for hosting (free tier)
- **JSON files** instead of a database (no database costs)

## Architecture Overview

### Data Flow

1. **GitHub Actions Workflow** (`.github/workflows/generate-data.yml`)
   - Runs every 6 hours (or manually)
   - Executes `bin/cron.php` to scrape and process new laws
   - Generates JSON files using `bin/generate-json-data.php`
   - Commits JSON files to the repository
   - Cloudflare Pages automatically deploys on commit

2. **Static Frontend** (`public/index.html`, `public/law.html`)
   - Pure HTML/JavaScript (no PHP server required)
   - Reads data from `data/index.json` and `data/laws/*.json`
   - Hosted on Cloudflare Pages

3. **Data Storage**
   - `data/index.json` - List of all laws (lightweight)
   - `data/laws/{master_id}.json` - Individual law details
   - No database needed!

## Setup Instructions

### 1. GitHub Secrets Configuration

Go to your GitHub repository → Settings → Secrets and variables → Actions, and add:

- `OPENAI_API_KEY` - Your OpenAI API key for AI summaries

### 2. Cloudflare Pages Setup

1. Go to [Cloudflare Dashboard](https://dash.cloudflare.com/)
2. Navigate to **Pages** → **Create a project**
3. Connect your GitHub repository
4. Configure build settings:
   - **Framework preset**: None (or Static)
   - **Build command**: (leave empty)
   - **Build output directory**: `public`
   - **Root directory**: `/` (root of repo)

5. Add environment variables (if needed):
   - None required for static site!

6. Deploy!

### 3. Manual Data Generation (Optional)

If you want to generate JSON files locally:

```bash
# Process laws (requires .env with OPENAI_API_KEY)
php bin/cron.php

# Generate JSON files
php bin/generate-json-data.php
```

### 4. Testing Locally

You can test the static site locally using any static file server:

```bash
# Using PHP built-in server
cd public
php -S localhost:8000

# Or using Python
cd public
python3 -m http.server 8000

# Or using Node.js http-server
npx http-server public -p 8000
```

Then visit `http://localhost:8000/index.html`

## Cost Breakdown

- **GitHub Actions**: Free (2,000 minutes/month for private repos, unlimited for public)
- **Cloudflare Pages**: Free (unlimited requests, 500 builds/month)
- **OpenAI API**: Pay-per-use (only cost)
- **Total**: ~$0 (only OpenAI API costs)

## Workflow Details

### Automated Processing

The GitHub Actions workflow:
1. Checks out the repository
2. Sets up PHP 8.2 with required extensions
3. Installs system dependencies (poppler-utils, tesseract-ocr)
4. Runs `bin/cron.php` to process new laws
5. Generates JSON files with `bin/generate-json-data.php`
6. Commits and pushes JSON files (if changed)

### Manual Trigger

You can manually trigger the workflow:
1. Go to **Actions** tab in GitHub
2. Select **Generate Law Data** workflow
3. Click **Run workflow**

## File Structure

```
Sentinel/
├── .github/
│   └── workflows/
│       └── generate-data.yml    # GitHub Actions workflow
├── app/                          # PHP backend (runs in GitHub Actions)
├── bin/
│   ├── cron.php                 # Main processing script
│   └── generate-json-data.php   # JSON generator
├── data/
│   ├── laws/                    # Individual law JSON files
│   │   └── {master_id}.json
│   └── index.json               # Law list index
├── public/                       # Static frontend (Cloudflare Pages)
│   ├── index.html               # Law list page
│   ├── law.html                 # Law detail page
│   ├── _redirects               # Cloudflare Pages redirects
│   └── ...
└── wrangler.toml                # Cloudflare Pages config
```

## Migration from SQLite

The old PHP-based frontend (`index.php`, `law.php`) is still available but not used in production. The new static version (`index.html`, `law.html`) reads from JSON files instead of SQLite.

## Troubleshooting

### JSON files not updating

1. Check GitHub Actions logs for errors
2. Verify `OPENAI_API_KEY` secret is set
3. Ensure workflow has permission to push commits

### Cloudflare Pages not deploying

1. Check Cloudflare Pages build logs
2. Verify build output directory is set to `public`
3. Ensure `_redirects` file is in `public/` directory

### Data not showing

1. Verify `data/index.json` exists and is valid JSON
2. Check browser console for fetch errors
3. Ensure CORS is not blocking requests (shouldn't be an issue on Cloudflare Pages)

## Benefits of This Approach

✅ **Zero hosting costs** (Cloudflare Pages free tier)  
✅ **No database maintenance** (just JSON files)  
✅ **Fast static site** (CDN-cached)  
✅ **Automatic deployments** (on every commit)  
✅ **Scalable** (Cloudflare handles traffic spikes)  
✅ **Simple architecture** (fewer moving parts)

## v6: Digital Ocean + chat pre každý zákon (GitHub Actions so seed DB)

Pre nasadenie na **Digital Ocean** s vetvou **v6** (panel „Opýtajte sa zákona“ pre každý zákon) sa používa workflow so **zdrojom dát v Actions**:

### Ako to funguje

1. **Seed DB v repozitári** (`data/sentinel.seed.db`) – obsahuje zoznam zákonov (metadata + AI sumáre). Workflow na začiatku skopíruje tento súbor na `data/sentinel.db`.
2. **Cron v Actions** – stiahne z NR SR zoznam, spracuje až 20 najnovších zákonov (stiahnutie, OCR, combined.txt, AI sumár), aktualizuje DB a vytvorí `storage/{master_id}/combined.txt`.
3. **Generovanie JSON** – `bin/generate-json-data.php` vygeneruje `public/data/laws/*.json` a `public/data/index.json` z DB.
4. **Kopírovanie .txt** – všetky `storage/*/combined.txt` sa skopírujú do `public/data/laws/*.txt` (existujúce .txt v repozitári sa nemazú, len sa pridávajú/aktualizujú).
5. **Commit a push** – zmeny (JSON + .txt) sa commitnú do vetvy v6.

Výsledok: na DO má každý zákon v zozname dostupný text pre chat (buď z predchádzajúceho commitu .txt, alebo z posledného behu workflow).

### Jednorazové vytvorenie seed DB

Lokálne (keď máš plnú DB a chceš ju použiť ako zdroj pre Actions):

```bash
cp data/sentinel.db data/sentinel.seed.db
git add data/sentinel.seed.db
git commit -m "Add seed DB for v6 workflow"
git push origin v6
```

Súbor `data/sentinel.seed.db` je v repozitári povolený (v `.gitignore` je výnimka `!data/sentinel.seed.db`). Lokálna `data/sentinel.db` sa do gitu necommituje.

### Čo potrebuje workflow v GitHub Actions

- **Secrets:** `OPENAI_API_KEY` (pre AI sumáre a chat).
- **Trigger:** každých 6 h (`schedule`), alebo manuálne (Actions → Generate Law Data (v6) → Run workflow), alebo pri push do v6 (cesty `bin/**`, `app/**`, workflow, `data/sentinel.seed.db`).

### Aktualizácia seed DB (voliteľne)

Ak pridáš nové zákony lokálne a chceš, aby mali v Actions plný zoznam:

```bash
cp data/sentinel.db data/sentinel.seed.db
git add data/sentinel.seed.db
git commit -m "Update seed DB for v6 workflow"
git push origin v6
```

Nasledujúci beh workflow použije aktualizovanú seed DB.

## Next Steps

1. Set up GitHub secrets
2. Configure Cloudflare Pages
3. Test the workflow manually
4. Monitor first automated run
5. Update your domain DNS to point to Cloudflare Pages (optional)
