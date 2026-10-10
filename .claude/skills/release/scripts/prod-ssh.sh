#!/usr/bin/env bash
# prod-ssh.sh — the ONLY sanctioned remote operations against Clarity prod.
#
# This is the security boundary, same discipline as terminal-log-triage's pull_logs.sh:
#   - verbs only, never a raw remote command string (there is no passthrough to widen)
#   - never add a verb that writes beyond `deploy`
#   - never widen the flag set (no tty, no forwarding, fail fast, never prompt)
#
# The prod box has NO documented server-side restriction (no ForceCommand, no restricted
# shell), so restraint is entirely client-side — this file is where it lives.
#
# VPN lifecycle is the CALLER's job (the release skill's Phase-7 trap block); this script
# assumes the tunnel is already up and fails fast (ConnectTimeout) when it is not.
#
# Usage: prod-ssh.sh deploy|verify
#   deploy  → php artisan app:deploy --no-confirm     (the ONE write; caller sets a 600000 ms Bash timeout)
#   verify  → git status --short + git log -1 + artisan version   (read-only deploy-state reconciliation)
set -euo pipefail

# Coupled: if the prod target ever changes, also update deploy-cycle.md's constants AND
# terminal-log-triage/config.local.sh's ssh_target_for() — all three together.
PROD_SSH="ubuntu@10.100.3.142"
PROD_DIR="~/code/platform"

[ $# -eq 1 ] || { echo "usage: prod-ssh.sh deploy|verify — no other remote commands are permitted" >&2; exit 2; }

case "$1" in
  deploy)
    REMOTE="cd $PROD_DIR && php artisan app:deploy --no-confirm" ;;
  verify)
    REMOTE="cd $PROD_DIR && git status --short && git log -1 --oneline && php artisan --version" ;;
  *)
    echo "prod-ssh.sh: unknown verb '$1' — only: deploy | verify" >&2; exit 2 ;;
esac

exec ssh -T -o BatchMode=yes -o ConnectTimeout=15 -o ClearAllForwardings=yes -o ForwardAgent=no "$PROD_SSH" "$REMOTE"
