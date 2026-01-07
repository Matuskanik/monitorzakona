#!/bin/bash

cd "$(dirname "$0")/.."

# Kill any existing server on port 8000
lsof -ti:8000 | xargs kill -9 2>/dev/null

# Start PHP built-in server
echo "Starting PHP development server on http://localhost:8000"
echo "Press Ctrl+C to stop"
echo ""
php -S localhost:8000 -t public

