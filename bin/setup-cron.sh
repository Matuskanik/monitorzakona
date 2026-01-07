#!/bin/bash

# Script to setup cron job for watchdog
# This will add a cron job to run watchdog.php every day at 00:00

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WATCHDOG_SCRIPT="$SCRIPT_DIR/watchdog.php"
CRON_LOG="$PROJECT_DIR/storage/logs/watchdog.log"

# Create logs directory if it doesn't exist
mkdir -p "$PROJECT_DIR/storage/logs"

# Create cron entry
CRON_ENTRY="0 0 * * * cd $PROJECT_DIR && php $WATCHDOG_SCRIPT >> $CRON_LOG 2>&1"

# Check if cron job already exists
if crontab -l 2>/dev/null | grep -q "watchdog.php"; then
    echo "Cron job for watchdog already exists."
    echo "Current cron jobs:"
    crontab -l | grep watchdog.php
    echo ""
    read -p "Do you want to remove the existing cron job and add a new one? (y/n) " -n 1 -r
    echo
    if [[ $REPLY =~ ^[Yy]$ ]]; then
        crontab -l 2>/dev/null | grep -v "watchdog.php" | crontab -
        (crontab -l 2>/dev/null; echo "$CRON_ENTRY") | crontab -
        echo "✓ Cron job updated successfully!"
    else
        echo "Cron job not modified."
        exit 0
    fi
else
    # Add new cron job
    (crontab -l 2>/dev/null; echo "$CRON_ENTRY") | crontab -
    echo "✓ Cron job added successfully!"
fi

echo ""
echo "Cron job details:"
echo "  Schedule: Every day at 00:00 (midnight)"
echo "  Script: $WATCHDOG_SCRIPT"
echo "  Log: $CRON_LOG"
echo ""
echo "To view current cron jobs, run: crontab -l"
echo "To remove this cron job, run: crontab -e (then delete the watchdog line)"

