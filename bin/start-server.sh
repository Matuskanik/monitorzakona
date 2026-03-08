#!/bin/bash

cd "$(dirname "$0")/.."

# Kill any existing server on port 8080
lsof -ti:8080 | xargs kill -9 2>/dev/null

# Start PHP built-in server (monitorzakona = latest version with period summaries & chat)
echo "Starting Monitor zákona (latest) on http://localhost:8080"
echo "Press Ctrl+C to stop"
echo ""
php -S localhost:8080 -t public

