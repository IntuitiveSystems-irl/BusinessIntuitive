#!/usr/bin/env bash
# Runs on the Mac. Uploads the intelligence tracker + lead + page, installs them
# on the server, then smoke-tests from here (NOT from the server -> avoids hairpin hang).
set -euo pipefail

H="root@89.167.28.217"
U="https://intelligence.businessintuitive.tech"
BASE="/Users/me./WebDev/Business Intuitive/geometric"
# Fail fast instead of waiting on any prompt; drop dead connections quickly.
SSHOPTS=(-o BatchMode=yes -o ConnectTimeout=10 -o ServerAliveInterval=5 -o ServerAliveCountMax=2)

echo "[1/4] Uploading files..."
scp "${SSHOPTS[@]}" \
  "$BASE/api/intelligence-tracker.php" \
  "$BASE/api/intelligence-lead.php" \
  "$BASE/public/intelligence.html" \
  "$BASE/deploy/intel-remote.sh" \
  "$H:/tmp/"

echo "[2/4] Installing on server..."
ssh "${SSHOPTS[@]}" "$H" 'bash /tmp/intel-remote.sh'

echo "[3/4] Smoke tests (from this Mac):"
printf '  GET tracker      (want 405): '; curl -sk --max-time 15 -o /dev/null -w '%{http_code}\n' "$U/api/intelligence-tracker.php" || echo "curl-timeout"
printf '  POST no-origin   (want 403): '; curl -sk --max-time 15 -o /dev/null -w '%{http_code}\n' -X POST -H 'Content-Type: application/json' -d '{"sid":"blocked12345678","page":"/"}' "$U/api/intelligence-tracker.php" || echo "curl-timeout"
printf '  POST valid       (want ok):  '; curl -sk --max-time 20 -X POST -H 'Content-Type: application/json' -H "Origin: $U" -d "{\"sid\":\"smoketest$(date +%s)\",\"page\":\"/?smoke=1\",\"referrer\":\"https://www.google.com/\",\"utm_source\":\"smoketest\",\"tz\":\"America/Los_Angeles\",\"lang\":\"en-US\",\"sw\":1440,\"sh\":900,\"hp\":\"\"}" "$U/api/intelligence-tracker.php" || echo "curl-timeout"; echo
printf '  GET /data db     (want 403): '; curl -sk --max-time 15 -o /dev/null -w '%{http_code}\n' "$U/data/intelligence-tracker.db" || echo "curl-timeout"
printf '  /sample-report   title:      '; curl -sk --max-time 15 "$U/sample-report" | grep -m1 -oE '<title>[^<]*' || echo "curl-timeout"

echo "[4/4] DONE"
