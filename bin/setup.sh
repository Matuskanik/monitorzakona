#!/bin/bash

echo "=== Sentinel Setup ==="
echo ""

# Check PHP version
PHP_VERSION=$(php -r "echo PHP_VERSION_ID;")
if [ "$PHP_VERSION" -lt 80200 ]; then
    echo "ERROR: PHP 8.2+ required. Found: $(php -r 'echo PHP_VERSION;')"
    exit 1
fi
echo "✓ PHP version OK"

# Check required extensions
REQUIRED_EXTENSIONS=("curl" "zip" "pdo_sqlite" "dom" "xml" "mbstring")
for ext in "${REQUIRED_EXTENSIONS[@]}"; do
    if ! php -m | grep -q "^${ext}$"; then
        echo "WARNING: PHP extension '${ext}' not found"
    else
        echo "✓ PHP extension '${ext}' found"
    fi
done

# Check composer
if ! command -v composer &> /dev/null; then
    echo "WARNING: Composer not found. Install it from https://getcomposer.org/"
else
    echo "✓ Composer found"
fi

# Check pdftotext
if command -v pdftotext &> /dev/null; then
    echo "✓ pdftotext found: $(which pdftotext)"
else
    echo "WARNING: pdftotext not found. Install poppler-utils for PDF extraction."
fi

# Create directories
echo ""
echo "Creating directories..."
mkdir -p storage/logs
mkdir -p storage/snapshots
mkdir -p data
chmod 755 storage storage/logs storage/snapshots data

# Copy .env if it doesn't exist
if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        cp .env.example .env
        echo "✓ Created .env from .env.example"
        echo "  IMPORTANT: Edit .env and set your OPENAI_API_KEY"
    else
        echo "WARNING: .env.example not found"
    fi
else
    echo "✓ .env already exists"
fi

# Install composer dependencies
if command -v composer &> /dev/null; then
    echo ""
    echo "Installing Composer dependencies..."
    composer install --no-dev
    echo "✓ Composer dependencies installed"
else
    echo ""
    echo "WARNING: Skipping composer install (composer not found)"
fi

# Make scripts executable
chmod +x bin/cron.php
chmod +x bin/selfcheck.php
echo "✓ Scripts made executable"

echo ""
echo "=== Setup Complete ==="
echo ""
echo "Next steps:"
echo "1. Edit .env and set your OPENAI_API_KEY"
echo "2. Run: php bin/selfcheck.php"
echo "3. Run: php bin/cron.php (to process laws)"
echo "4. Configure your web server to point to the public/ directory"


