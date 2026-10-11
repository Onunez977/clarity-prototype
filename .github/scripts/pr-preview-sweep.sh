#!/usr/bin/env bash
# Reap platform preview environments whose PR is closed or merged.
# Sourceable: helpers are defined first; main() only runs when executed directly.
set -euo pipefail

# Parse `docker ps` names (stdin) into unique PR numbers. Matches ONLY
# platform-pr-<N> (never pre-platform's bare platform-qa-* containers).
extract_pr_numbers() {
  grep -oE 'platform-pr-[0-9]+' | sed -E 's/platform-pr-//' | sort -un
}

# 0 = reap (PR closed/merged), 1 = keep.
pr_is_finished() {
  local n="$1" state
  state="$(gh pr view "$n" --repo "$GH_REPO" --json state --jq '.state' 2>/dev/null || echo UNKNOWN)"
  case "$state" in
    CLOSED|MERGED) return 0 ;;
    *) return 1 ;;
  esac
}

reap_preview() {
  local n="$1"
  echo "::notice::Reaping preview for closed/merged PR #$n"
  # SCOPED removal only — never the top-level `kamal remove`, which additionally
  # tears down the SHARED host-wide kamal-proxy (pre-platform + prod route through
  # it) and does a host-wide `docker logout`. These two commands touch only
  # platform-pr-<N>. (AWS_ACCOUNT_ID / KAMAL_SERVER come from the job env.)
  export KAMAL_PREVIEW=true
  export KAMAL_SERVICE="platform-pr-$n"
  export KAMAL_PROXY_HOST="pr-$n-platform.internal.insightlpr.io"
  export KAMAL_PROXY_SSL=false
  # `kamal accessory remove` takes -y (--confirmed); `kamal app remove` has no such
  # flag in Kamal 2.x and runs non-interactively as-is.
  kamal accessory remove redis -y || echo "::warning::redis accessory remove failed for PR #$n"
  kamal app remove || echo "::warning::kamal app remove failed for PR #$n"
  # Local Docker daemon (the sweeper runs on the colocated Kamal host).
  docker volume rm "platform_pr_${n}_storage" 2>/dev/null || true
}

main() {
  local names numbers reaped=0 kept=0
  names="$(ssh -o StrictHostKeyChecking=accept-new "${KAMAL_SSH_USER}@${KAMAL_SERVER}" \
    "docker ps --format '{{.Names}}' --filter name=platform-pr-")"
  numbers="$(printf '%s\n' "$names" | extract_pr_numbers || true)"

  if [ -z "$numbers" ]; then
    echo "No preview environments found."
    return 0
  fi

  while IFS= read -r n; do
    [ -z "$n" ] && continue
    if pr_is_finished "$n"; then
      reap_preview "$n"; reaped=$((reaped + 1))
    else
      echo "Keeping preview for open PR #$n"; kept=$((kept + 1))
    fi
  done <<< "$numbers"

  {
    echo "## PR preview sweep"
    echo "- Reaped: $reaped"
    echo "- Kept:   $kept"
  } >> "${GITHUB_STEP_SUMMARY:-/dev/stdout}"
}

if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
  main "$@"
fi
