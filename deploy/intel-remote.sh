#!/usr/bin/env bash
# Runs ON the server. Installs the uploaded files + ensures the tracker data dir
# is writable by php-fpm. Invoked by deploy-intelligence.sh via: ssh host 'bash /tmp/intel-remote.sh'
set -e

L=$(command -v php8.3 || command -v php || true)
if [ -n "$L" ]; then
  "$L" -l /tmp/intelligence-tracker.php
  "$L" -l /tmp/intelligence-lead.php
fi

cp /tmp/intelligence-tracker.php /var/www/geometric/api/intelligence-tracker.php
cp /tmp/intelligence-lead.php    /var/www/geometric/api/intelligence-lead.php
cp /tmp/intelligence.html        /var/www/geometric/dist/intelligence.html

F=$(grep -hoP '^user\s*=\s*\K\S+' /etc/php/8.3/fpm/pool.d/www.conf 2>/dev/null | head -1)
[ -z "$F" ] && F=www-data
mkdir -p /var/www/geometric/data
chown -R "$F":"$F" /var/www/geometric/data
chmod 775 /var/www/geometric/data

echo "REMOTE_DEPLOY_OK fpm_user=$F"
