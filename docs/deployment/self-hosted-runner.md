# Self-hosted GitHub Actions runner (Kamal deploys)

The [`Kamal Deploy (pre-platform)`](../../.github/workflows/kamal-deploy.yml) workflow runs on:

```yaml
runs-on: [self-hosted, kamal]
```

So it needs a **repo-level self-hosted runner labeled `kamal`** that lives on the Kamal
host and can reach it over SSH. This is set up the same way as the existing
`overwatch` / `backyard` / `integrate` runners, which are **colocated on the Kamal box
`10.100.3.13`**.

> **Status:** the `insight-lpr/platform` runner is registered and running on `10.100.3.13`
> (service `actions.runner.insight-lpr-platform.10.100.3.13.service`). This doc records how
> it was set up and how to recover/replicate it.

## Conventions on `10.100.3.13`

Each repo gets its own runner, colocated on the Kamal host:

| Item | Value |
|---|---|
| Runner dir | `~ubuntu/actions-runner-<repo>` (e.g. `actions-runner-platform`) |
| Scope | **repo-level** — `https://github.com/insight-lpr/<repo>` |
| Runner name | `10.100.3.13` (the host IP) |
| Custom label | `kamal` (`self-hosted`, `Linux`, `X64` are added automatically) |
| Service | `actions.runner.insight-lpr-<repo>.10.100.3.13.service`, run as `ubuntu` |

The `ubuntu` user is already in the `docker` group, has passwordless `sudo`, and can SSH to
the Kamal target (itself), so `KAMAL_SSH_KEY` is left **unset** — Kamal and the redis-init
step use the runner user's own SSH config.

## Setup (one-time, per repo)

Run from a workstation with `gh` (repo admin) and SSH access to `10.100.3.13`:

```bash
# 1. Mint a repo-level registration token (expires in ~1h).
REG=$(gh api -X POST repos/insight-lpr/platform/actions/runners/registration-token --jq .token)

# 2. Configure + install the runner on the Kamal host, matching the other repos.
ssh -o BatchMode=yes 10.100.3.13 "REG='$REG' bash -s" <<'REMOTE'
set -euo pipefail
cd ~
mkdir -p actions-runner-platform && cd actions-runner-platform
V=$(curl -fsSL https://api.github.com/repos/actions/runner/releases/latest | grep -oP '"tag_name": "v\K[^"]+')
if [ ! -f config.sh ]; then
  curl -fsSL -o runner.tar.gz "https://github.com/actions/runner/releases/download/v${V}/actions-runner-linux-x64-${V}.tar.gz"
  tar xzf runner.tar.gz && rm -f runner.tar.gz
fi
./config.sh --unattended --replace \
  --url https://github.com/insight-lpr/platform \
  --token "$REG" \
  --name 10.100.3.13 \
  --labels kamal \
  --work _work
sudo ./svc.sh install ubuntu
sudo ./svc.sh start
REMOTE
```

The runner self-updates, so the downloaded version converges with the other runners.

## Verify

```bash
# GitHub side — expect status "online", labels self-hosted/Linux/X64/kamal:
gh api repos/insight-lpr/platform/actions/runners \
  --jq '.runners[] | {name, status, labels:[.labels[].name]}'

# Host side:
ssh 10.100.3.13 'systemctl is-active actions.runner.insight-lpr-platform.10.100.3.13.service'
```

Then run **Actions → Kamal Deploy (pre-platform) → Run workflow** and confirm the job lands
on this runner.

## Remove

```bash
ssh 10.100.3.13 'cd ~/actions-runner-platform && sudo ./svc.sh stop && sudo ./svc.sh uninstall'
REM=$(gh api -X POST repos/insight-lpr/platform/actions/runners/remove-token --jq .token)
ssh 10.100.3.13 "cd ~/actions-runner-platform && ./config.sh remove --token '$REM'"
```
