#!/usr/bin/env bash
set -euo pipefail
# Native AL2023 scheduler; do not depend on cron being preinstalled.
install -m 0750 -o root -g root /var/app/current/deploy/aws/run-worker.sh /usr/local/bin/waithaka-worker
rm -f /etc/cron.d/waithaka-worker
cat > /etc/systemd/system/waithaka-worker.service <<'SERVICE'
[Unit]
Description=Waithaka publishing and email worker
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
User=root
WorkingDirectory=/var/app/current
ExecStart=/usr/local/bin/waithaka-worker
TimeoutStartSec=15min
UMask=0077
NoNewPrivileges=true
StandardOutput=append:/var/log/waithaka-worker.log
StandardError=append:/var/log/waithaka-worker.log
SERVICE
cat > /etc/systemd/system/waithaka-worker.timer <<'TIMER'
[Unit]
Description=Run Waithaka work every minute after the previous run

[Timer]
OnBootSec=60s
OnUnitInactiveSec=60s
AccuracySec=5s
Unit=waithaka-worker.service

[Install]
WantedBy=timers.target
TIMER
chmod 0644 /etc/systemd/system/waithaka-worker.service /etc/systemd/system/waithaka-worker.timer
systemctl daemon-reload
systemctl enable --now waithaka-worker.timer
systemctl is-active --quiet waithaka-worker.timer
cat > /etc/logrotate.d/waithaka-worker <<'LOGROTATE'
/var/log/waithaka-worker.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    create 0640 root root
}
LOGROTATE
chmod 0644 /etc/logrotate.d/waithaka-worker
