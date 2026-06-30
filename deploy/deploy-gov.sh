#!/usr/bin/env bash
# Runs on the Mac. Uploads the federal capability statement page + assets + API endpoint,
# installs them on the server, then smoke-tests from here (NOT from the server -> avoids hairpin hang).
set -euo pipefail

H="root@89.167.28.217"
U="https://gov.businessintuitive.tech"
BASE="/Users/me./WebDev/Business Intuitive/geometric"
SSHOPTS=(-o BatchMode=yes -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)

echo "[1/4] Uploading files..."
scp "${SSHOPTS[@]}" \
  "$BASE/api/gov-lead.php" \
  "$BASE/public/gov.html" \
  "$BASE/public/assets/gov.js" \
  "$BASE/public/gov-robots.txt" \
  "$BASE/public/gov-sitemap.xml" \
  "$BASE/deploy/nginx/gov-landing.conf" \
  "$BASE/deploy/gov-remote.sh" \
  "$H:/tmp/"

echo "[2/4] Installing on server..."
ssh "${SSHOPTS[@]}" "$H" 'bash /tmp/gov-remote.sh'

echo "[3/4] Smoke tests (from this Mac):"
printf '  GET /               (want 200): '; curl -sk --max-time 15 -o /dev/null -w '%{http_code}\n' "$U/" || echo "curl-timeout"
printf '  GET /robots.txt     (want 200): '; curl -sk --max-time 15 -o /dev/null -w '%{http_code}\n' "$U/robots.txt" || echo "curl-timeout"
printf '  GET /sitemap.xml    (want 200): '; curl -sk --max-time 15 -o /dev/null -w '%{http_code}\n' "$U/sitemap.xml" || echo "curl-timeout"
printf '  GET /assets/gov.js  (want 200): '; curl -sk --max-time 15 -o /dev/null -w '%{http_code}\n' "$U/assets/gov.js" || echo "curl-timeout"
printf '  POST no-origin     (want 403): '; curl -sk --max-time 15 -o /dev/null -w '%{http_code}\n' -X POST -H 'Content-Type: application/json' -d '{"name":"test"}' "$U/api/gov-lead.php" || echo "curl-timeout"
printf '  POST valid         (want ok):  '; curl -sk --max-time 20 -X POST -H 'Content-Type: application/json' -H "Origin: $U" -d '{"name":"Smoke Test","organization":"Test Org","role":"Contracting Officer","inquiry_type":"Capabilities Briefing","email":"smoke@businessintuitive.tech","phone":"","solicitation":"","notes":"","company_website":"","page":"gov-capability-statement","referrer":""}' "$U/api/gov-lead.php" || echo "curl-timeout"; echo
printf '  GET /data/          (want 403): '; curl -sk --max-time 15 -o /dev/null -w '%{http_code}\n' "$U/data/" || echo "curl-timeout"
printf '  GET /logs/          (want 403): '; curl -sk --max-time 15 -o /dev/null -w '%{http_code}\n' "$U/logs/" || echo "curl-timeout"

echo "[4/4] DONE"
