#!/usr/bin/env bash
# Runs ON the server (89.167.28.217). Adds fail2ban + makes SSH key-only.
# Safe: validates sshd config before reload; uses `reload` (keeps current sessions);
# key auth is already proven working, so removing the password fallback can't lock us out.
set -e

echo "===== current ufw ====="
ufw status verbose || true

echo "===== install fail2ban ====="
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq fail2ban

echo "===== /etc/fail2ban/jail.local ====="
cat > /etc/fail2ban/jail.local <<'JAIL'
[DEFAULT]
# never ban localhost or the admin Mac
ignoreip = 127.0.0.1/8 ::1 68.189.111.178
bantime  = 2h
findtime = 10m
maxretry = 5
backend  = systemd

[sshd]
enabled  = true
port     = 22
maxretry = 4
bantime  = 4h
JAIL

systemctl enable fail2ban >/dev/null 2>&1 || true
systemctl restart fail2ban
sleep 2
echo "----- fail2ban sshd jail -----"
fail2ban-client status sshd 2>/dev/null || { echo "(jail not ready yet) active=$(systemctl is-active fail2ban)"; }

echo "===== SSH key-only drop-in (00- sorts BEFORE 50-cloud-init so it wins) ====="
cat > /etc/ssh/sshd_config.d/00-hardening.conf <<'SSHD'
# Key-only SSH. Managed by harden-server.sh
PubkeyAuthentication yes
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin prohibit-password
SSHD

echo "----- validating sshd config -----"
if sshd -t; then
  echo "sshd -t OK -> reloading ssh (existing session stays up)"
  systemctl reload ssh 2>/dev/null || systemctl reload sshd
else
  echo "!!! sshd -t FAILED -> removing drop-in, NOT reloading"
  rm -f /etc/ssh/sshd_config.d/00-hardening.conf
  exit 1
fi

echo "----- effective sshd settings -----"
sshd -T | grep -Ei '^(passwordauthentication|permitrootlogin|pubkeyauthentication|kbdinteractiveauthentication) '
echo "HARDEN_DONE"
