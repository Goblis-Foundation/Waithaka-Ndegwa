#!/usr/bin/env bash
set -euo pipefail
# Same workload identity and runtime launcher as the existing worker. No new
# provider grant, purchase, account, transport setting or historical queue release.
systemd-analyze calendar '*-*-01 00:00:00 Africa/Nairobi' >/dev/null
cat > /etc/systemd/system/waithaka-monthly-roundup.service <<'SERVICE'
[Unit]
Description=Publish the previous month's public roundup and scoped newsletter
After=network-online.target time-sync.target
Wants=network-online.target

[Service]
Type=oneshot
User=root
WorkingDirectory=/var/app/current
ExecStart=/usr/bin/php /var/app/current/deploy/aws/worker-launcher.php --monthly-roundup
TimeoutStartSec=180s
UMask=0077
NoNewPrivileges=true
StandardOutput=append:/var/log/waithaka-worker.log
StandardError=append:/var/log/waithaka-worker.log
SERVICE
cat > /etc/systemd/system/waithaka-monthly-roundup.timer <<'TIMER'
[Unit]
Description=Waithaka monthly newsletter at midnight on the first in Nairobi

[Timer]
OnCalendar=*-*-01 00:00:00 Africa/Nairobi
AccuracySec=1s
RandomizedDelaySec=0
Persistent=true
Unit=waithaka-monthly-roundup.service

[Install]
WantedBy=timers.target
TIMER
chmod 0644 /etc/systemd/system/waithaka-monthly-roundup.service /etc/systemd/system/waithaka-monthly-roundup.timer
systemctl daemon-reload
systemctl enable --now waithaka-monthly-roundup.timer
systemctl is-active --quiet waithaka-monthly-roundup.timer
# Initialise the exact policy and sender binding. The application refuses any
# publication before 1 November 2026 and never publishes October's draft early.
systemctl start waithaka-monthly-roundup.service
systemctl show waithaka-monthly-roundup.timer -p ActiveState -p NextElapseUSecRealtime --no-pager
