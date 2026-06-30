#!/usr/bin/env bash
# Regenerates /public/investor-readiness-guide.pdf from the HTML source.
# Usage: bash scripts/build-investor-pdf.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/public/investor-readiness-guide.html"
OUT="$ROOT/public/investor-readiness-guide.pdf"

CHROME="/Applications/Google Chrome.app/Contents/MacOS/Google Chrome"
if [ ! -x "$CHROME" ]; then
  echo "Chrome not found at $CHROME" >&2
  exit 1
fi

"$CHROME" \
  --headless=new \
  --disable-gpu \
  --no-sandbox \
  --hide-scrollbars \
  --no-pdf-header-footer \
  --print-to-pdf-no-header \
  --virtual-time-budget=10000 \
  --print-to-pdf="$OUT" \
  "file://$SRC"

echo "Wrote $OUT"
ls -la "$OUT"
