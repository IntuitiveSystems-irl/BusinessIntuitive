#!/usr/bin/env bash
# ──────────────────────────────────────────────────────────────────
# Deploy to businessintuitive.tech (VPS: root@89.167.28.217)
#
# Workflow:
#   1) npm run build   → rebuild dist/
#   2) rsync the static home + shared brand assets to /tmp/bi-deploy on VPS
#   3) ssh into VPS, move files into /var/www/geometric/dist(/assets)
#   4) verify
#
#   NOTE: ships the shared assets (brand.css, brand-mark.svg, chatbot.js)
#   that intelligence.html + gov.html also depend on. Run this BEFORE the
#   subdomain deploys (deploy/deploy-intelligence.sh, deploy/deploy-gov.sh).
#
# nginx-on-host serves /var/www/geometric/dist directly,
# so no Docker rebuild is needed for HTML/JS/CSS changes.
#
# Usage:
#   ./scripts/deploy.sh
# ──────────────────────────────────────────────────────────────────

set -e

VPS="root@89.167.28.217"
REMOTE_DIST="/var/www/geometric/dist"
LOCAL_DIST="$(cd "$(dirname "$0")/.." && pwd)/dist"

echo "→ Building locally"
npm run build

echo ""
echo "→ Cache-busting shared asset URLs in dist/index.html"
CACHE_BUST="$(date +%s)"
# Bust the rebrand's shared assets so returning visitors get fresh CSS/JS
sed -i.bak \
  -e "s|/assets/brand\.css[^\"']*|/assets/brand.css?v=${CACHE_BUST}|g" \
  -e "s|/assets/brand-mark\.svg[^\"']*|/assets/brand-mark.svg?v=${CACHE_BUST}|g" \
  -e "s|/assets/chatbot\.js[^\"']*|/assets/chatbot.js?v=${CACHE_BUST}|g" \
  -e "s|/assets/bi-analytics\.js[^\"']*|/assets/bi-analytics.js?v=${CACHE_BUST}|g" \
  "$LOCAL_DIST/index.html"
rm -f "$LOCAL_DIST/index.html.bak"
echo "  → ?v=${CACHE_BUST}"

echo ""
echo "→ Uploading to $VPS:/tmp/bi-deploy/"
rsync -avz \
  "$LOCAL_DIST/index.html" \
  "$LOCAL_DIST/brand.html" \
  "$LOCAL_DIST/assets/brand.css" \
  "$LOCAL_DIST/assets/brand-mark.svg" \
  "$LOCAL_DIST/assets/chatbot.js" \
  "$LOCAL_DIST/assets/bi-analytics.js" \
  "$VPS:/tmp/bi-deploy/" 2>&1 | tail -8

echo ""
echo "→ Activating on $VPS"
ssh "$VPS" "
  mkdir -p $REMOTE_DIST/assets &&
  rsync /tmp/bi-deploy/index.html      $REMOTE_DIST/index.html &&
  rsync /tmp/bi-deploy/brand.html      $REMOTE_DIST/brand.html &&
  rsync /tmp/bi-deploy/brand.css       $REMOTE_DIST/assets/brand.css &&
  rsync /tmp/bi-deploy/brand-mark.svg  $REMOTE_DIST/assets/brand-mark.svg &&
  rsync /tmp/bi-deploy/chatbot.js      $REMOTE_DIST/assets/chatbot.js &&
  rsync /tmp/bi-deploy/bi-analytics.js $REMOTE_DIST/assets/bi-analytics.js &&
  echo 'ACTIVATED ✓' &&
  ls -la $REMOTE_DIST/index.html $REMOTE_DIST/assets/brand.css $REMOTE_DIST/assets/brand-mark.svg $REMOTE_DIST/assets/chatbot.js $REMOTE_DIST/assets/bi-analytics.js
"

echo ""
echo "✓ Deployed. Verify at: https://businessintuitive.tech"
