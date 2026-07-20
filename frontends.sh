#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

echo "=== Worktide Frontends ==="
echo ""

# SPA
echo "[SPA]    Starting on http://127.0.0.1:5173"
cd "$ROOT/worktide-web"
CI=true pnpm install --silent 2>/dev/null
./node_modules/.bin/vite --host 0.0.0.0 --port 5173 &
SPA_PID=$!

# Portal
echo "[Portal] Starting on http://127.0.0.1:5174"
cd "$ROOT/worktide-portal"
CI=true pnpm install --silent 2>/dev/null
./node_modules/.bin/vite --host 0.0.0.0 --port 5174 &
PORTAL_PID=$!

echo ""
echo "  SPA:    http://127.0.0.1:5173"
echo "  Portal: http://127.0.0.1:5174"
echo "  Press Ctrl+C to stop both."

trap "kill $SPA_PID $PORTAL_PID 2>/dev/null; exit 0" INT TERM
wait
