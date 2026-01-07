# Quick Setup Guide

## 1. Create .env file

Create a `.env` file in the project root with this content:

```env
# OpenAI Configuration
OPENAI_API_KEY=your_openai_api_key_here
OPENAI_MODEL=gpt-4o-mini
OPENAI_MAX_TOKENS=2000

# Application Configuration
BASE_URL=http://localhost
APP_NAME=Sentinel Law Monitor

# Scraping Configuration
NR_SR_LIST_URL=https://www.nrsr.sk/web/default.aspx?SectionId=184
REQUEST_DELAY_SECONDS=3
MAX_RETRIES=3
RETRY_DELAY_SECONDS=5

# Paths
STORAGE_PATH=storage
DATA_PATH=data
LOG_PATH=storage/logs

# Database
DB_PATH=data/sentinel.db
```

**IMPORTANT:** Replace `your_openai_api_key_here` with your actual OpenAI API key.

## 2. Install dependencies

```bash
composer install
```

## 3. Run self-check

```bash
php bin/selfcheck.php
```

## 4. Process laws manually

```bash
php bin/cron.php
```

## 5. Set up cron (optional)

Add to crontab:
```bash
0 */6 * * * cd /path/to/Sentinel && /usr/bin/php bin/cron.php >> /path/to/Sentinel/storage/logs/cron.log 2>&1
```

## 6. Configure web server

Point your web server document root to the `public/` directory.


