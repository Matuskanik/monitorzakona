# Sentinel Law Monitor

MVP web app in vanilla PHP that monitors the Slovak National Council website "Last approved laws" and publishes AI summaries.

## Features

- Automatically scrapes newly approved laws from NR SR website
- Downloads attachments (DOCX/PDF, prefers ZIP bundles)
- Extracts text from documents
- Generates AI summaries using OpenAI GPT
- Displays summaries on a clean web interface
- Runs automatically via cron

## Requirements

### System Packages

- PHP 8.2+ with extensions:
  - `curl`
  - `zip`
  - `pdo_sqlite`
  - `dom`
  - `xml`
  - `mbstring`

- `poppler-utils` (for PDF text extraction and OCR):
  ```bash
  # Ubuntu/Debian
  sudo apt-get install poppler-utils
  
  # macOS
  brew install poppler
  
  # CentOS/RHEL
  sudo yum install poppler-utils
  ```

- `tesseract-ocr` (for OCR on scanned/image-based PDFs):
  ```bash
  # Ubuntu/Debian
  sudo apt-get install tesseract-ocr tesseract-ocr-slk
  
  # macOS
  brew install tesseract tesseract-lang
  
  # CentOS/RHEL
  sudo yum install tesseract tesseract-langpack-slk
  ```
  
  **Note:** The `tesseract-ocr-slk` package provides Slovak language support for better OCR accuracy. If not available, Tesseract will use the default language.

- `tesseract` (for OCR on scanned PDFs):
  ```bash
  # Ubuntu/Debian
  sudo apt-get install tesseract-ocr tesseract-ocr-slk
  
  # macOS
  brew install tesseract tesseract-lang
  
  # CentOS/RHEL
  sudo yum install tesseract tesseract-langpack-slk
  ```
  
  Note: The `slk` language pack provides Slovak language support for OCR.

### PHP Dependencies

- None required (vanilla PHP only)
- Composer is used for autoloading only

## Setup

1. **Clone/Download the project**

2. **Install dependencies:**
   ```bash
   composer install
   ```

3. **Configure environment:**
   ```bash
   cp .env.example .env
   ```
   
   Edit `.env` and set:
   - `OPENAI_API_KEY` - Your OpenAI API key
   - `BASE_URL` - Your website URL (for production)
   - Adjust other settings as needed

4. **Set permissions:**
   ```bash
   chmod +x bin/cron.php
   chmod +x bin/selfcheck.php
   ```

5. **Run self-check:**
   ```bash
   php bin/selfcheck.php
   ```

6. **Configure web server:**
   
   Point your web server document root to the `public/` directory.
   
   Example for Apache (`.htaccess` already included):
   ```
   DocumentRoot /path/to/Sentinel/public
   ```
   
   Example for Nginx:
   ```nginx
   server {
       root /path/to/Sentinel/public;
       index index.php;
       
       location / {
           try_files $uri $uri/ /index.php?$query_string;
       }
       
       location ~ \.php$ {
           fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
           fastcgi_index index.php;
           include fastcgi_params;
           fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
       }
   }
   ```

## Usage

### Manual Worker Run

Process laws manually:
```bash
php bin/cron.php
```

### Cron Setup

Add to crontab to run automatically (e.g., every 6 hours):
```bash
crontab -e
```

Add:
```
0 */6 * * * cd /path/to/Sentinel && /usr/bin/php bin/cron.php >> /path/to/Sentinel/storage/logs/cron.log 2>&1
```

Or more frequently (every 2 hours):
```
0 */2 * * * cd /path/to/Sentinel && /usr/bin/php bin/cron.php >> /path/to/Sentinel/storage/logs/cron.log 2>&1
```

### Web Interface

- **List page:** `http://your-domain/index.php`
- **Law detail:** `http://your-domain/law.php?id=1`

## Project Structure

```
Sentinel/
├── app/
│   ├── Config.php           # Configuration loader
│   ├── Database.php         # SQLite database wrapper
│   ├── Logger.php           # Logging system
│   ├── Scraper.php          # NR SR website scraper
│   ├── DocumentExtractor.php # DOCX/PDF text extraction
│   ├── OpenAIClient.php     # OpenAI API client
│   └── LawProcessor.php     # Main processing orchestrator
├── bin/
│   ├── cron.php             # Worker script
│   └── selfcheck.php        # Self-check utility
├── public/
│   ├── index.php            # Law list page
│   └── law.php              # Law detail page
├── storage/
│   ├── logs/                # Application logs
│   ├── snapshots/           # HTML snapshots
│   └── {masterId}/          # Per-law storage
│       ├── source_detail.html
│       ├── combined.txt
│       └── attachments/
├── data/
│   └── sentinel.db          # SQLite database
├── .env                     # Configuration (create from .env.example)
├── .env.example             # Configuration template
├── composer.json
└── README.md
```

## Database Schema

- **laws**: Stores law metadata and AI summaries
- **attachments**: Stores attachment file references
- **processing_log**: Logs processing status per law

## Configuration

Key `.env` variables:

- `OPENAI_API_KEY`: Required - Your OpenAI API key
- `OPENAI_MODEL`: Model to use (default: `gpt-4o-mini`)
- `NR_SR_LIST_URL`: URL to scrape (default: NR SR list page)
- `REQUEST_DELAY_SECONDS`: Delay between requests (default: 3)
- `MAX_RETRIES`: Retry attempts (default: 3)
- `DB_PATH`: SQLite database path (default: `data/sentinel.db`)

## Logging

Logs are written to `storage/logs/app.log` with timestamps and log levels.

## Idempotency

The system tracks content hashes. If a law's content hasn't changed, it won't be reprocessed (saving API costs).

## Rate Limiting

- Configurable delay between requests (default: 3 seconds)
- Retries with exponential backoff
- Polite User-Agent header

## Troubleshooting

1. **Self-check fails:**
   - Run `php bin/selfcheck.php` to diagnose issues
   - Check PHP extensions are installed
   - Verify `pdftotext` is available

2. **No laws found:**
   - Check if NR SR website structure changed
   - Review `storage/snapshots/` HTML files
   - Check logs in `storage/logs/app.log`

3. **PDF extraction fails:**
   - Install `poppler-utils`
   - Check `pdftotext` is in PATH
   - PDFs will be skipped if extraction fails (logged)

4. **OpenAI errors:**
   - Verify API key is correct
   - Check API quota/limits
   - Review error messages in logs

## Security Notes

- Never expose `.env` file (already in `.gitignore`)
- All output is sanitized with `htmlspecialchars()`
- SQL uses prepared statements
- File paths are validated

## License

MIT

