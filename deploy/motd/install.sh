#!/usr/bin/env bash
# Installs the PSO status MOTD on CT 199 (pso-services, host technodrome).
# Run this from inside the container as root:
#   pct exec 199 -- bash < deploy/motd/install.sh
# or copy 00-pso-status in first and run manually — see the steps below.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

install -m 0755 "${SCRIPT_DIR}/00-pso-status" /etc/update-motd.d/00-pso-status

if ! grep -q PSO_MOTD_SHOWN /etc/bash.bashrc; then
  cat >> /etc/bash.bashrc << 'EOF'

# PSO status MOTD — pct enter attaches directly and skips pam_motd/update-motd.d,
# so run it here instead (once per interactive shell, only when attached to a tty).
if [ -t 1 ] && [ -z "${PSO_MOTD_SHOWN:-}" ]; then
    export PSO_MOTD_SHOWN=1
    run-parts /etc/update-motd.d/ 2>/dev/null
fi
EOF
fi

echo "Installed. Test with: run-parts /etc/update-motd.d/"
