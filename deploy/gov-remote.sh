#!/usr/bin/env bash
# Runs on the remote server (89.167.28.217). Copies uploaded files to final destinations,
# installs nginx config, and sets permissions.
set -euo pipefail

API_DIR="/var/www/geometric/api"
DIST_DIR="/var/www/geometric/dist"
NGINX_SITES="/etc/nginx/sites-available"
NGINX_ENABLED="/etc/nginx/sites-enabled"
DATA_DIR="/var/www/geometric/data"

echo "[remote] Copying PHP endpoint..."
cp /tmp/gov-lead.php "$API_DIR/"
chown root:root "$API_DIR/gov-lead.php"
chmod 644 "$API_DIR/gov-lead.php"

echo "[remote] Copying HTML page..."
cp /tmp/gov.html "$DIST_DIR/"
chown root:root "$DIST_DIR/gov.html"
chmod 644 "$DIST_DIR/gov.html"

echo "[remote] Copying JS asset..."
mkdir -p "$DIST_DIR/assets"
cp /tmp/gov.js "$DIST_DIR/assets/"
chown root:root "$DIST_DIR/assets/gov.js"
chmod 644 "$DIST_DIR/assets/gov.js"

echo "[remote] Copying SEO files..."
cp /tmp/gov-robots.txt "$DIST_DIR/robots.txt"
chown root:root "$DIST_DIR/robots.txt"
chmod 644 "$DIST_DIR/robots.txt"

cp /tmp/gov-sitemap.xml "$DIST_DIR/sitemap.xml"
chown root:root "$DIST_DIR/sitemap.xml"
chmod 644 "$DIST_DIR/sitemap.xml"

echo "[remote] Installing nginx vhost..."
cp /tmp/gov-landing.conf "$NGINX_SITES/gov-landing"
ln -sf "$NGINX_SITES/gov-landing" "$NGINX_ENABLED/gov-landing"

echo "[remote] Testing nginx config..."
nginx -t

echo "[remote] Reloading nginx..."
systemctl reload nginx

echo "[remote] Ensuring data directory exists for logs..."
mkdir -p "$DATA_DIR"
chown www-data:www-data "$DATA_DIR"
chmod 775 "$DATA_DIR"

echo "[remote] DONE"
