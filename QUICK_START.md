# Quick Start Guide

## Start the Web Server

**Option 1: Using the start script**
```bash
cd /Users/apple/aibnb_t/Sentinel
./bin/start-server.sh
```

**Option 2: Manual start**
```bash
cd /Users/apple/aibnb_t/Sentinel
php -S localhost:8000 -t public
```

The server will start on **http://localhost:8000**

## Access the Web Interface

Once the server is running, open your browser and visit:

- **Law List:** http://localhost:8000/index.php
- **Law Detail:** http://localhost:8000/law.php?id=1

## Process Laws

In a new terminal window:
```bash
cd /Users/apple/aibnb_t/Sentinel
php bin/cron.php
```

This will:
1. Fetch laws from NR SR website
2. Download ZIP bundles
3. Extract text (or mark as image-based if scanned)
4. Generate AI summaries
5. Save to database

## Troubleshooting

If you get "Connection Refused":
1. Make sure the server is running (check with `lsof -i:8000`)
2. Try stopping any existing server: `lsof -ti:8000 | xargs kill -9`
3. Restart the server using the script above

If pages show errors:
- Check that `.env` file exists and has your OpenAI API key
- Check `storage/logs/app.log` for errors
- Run `php bin/selfcheck.php` to verify setup


