---
name: otp-login
description: >
  Log an agent into the local platform/Clarity site through the REAL OTP login form
  (not the /__qa/login bypass). Flips the mail driver to `log` and the queue to `sync` so
  the one-time password renders into storage/logs/laravel.log in-request, drives the
  Filament login form with agent-browser, reads the OTP back from the log (falling back to
  the server-side cache), and completes login for the admin (`app`) or client portal
  (`client`). Works in a Claude Code worktree and on the main checkout (backs up and
  restores the real .env). Optional args: an email to log in as, and `client` to target the
  client portal. TRIGGER only on explicit asks — "/otp-login", "log in to the local site",
  "log in via OTP", "log me in as <email>", "otp login as <email> on client". Do NOT trigger
  on generic "test this" / "click around" / "QA this".
---

# otp-login

Drive the **real** OTP login form end-to-end and leave the agent in an authenticated
browser session.

## When to use this

Use this when you need a logged-in browser session and any of:

- the **client portal** (`/client`), which `/__qa/login` can't reach,
- a **specific user by email** rather than a numeric id,
- to exercise the **actual login flow** (the form + OTP path) itself.

For a quick admin-as-id-1 session where the flow itself doesn't matter, `/__qa/login/{id}`
is faster. This skill is the complement: it goes through the genuine form.

## How the flow works (so the steps make sense)

- Login pages: `<APP_URL>/app/login` (admin) and `<APP_URL>/client/login` (client portal),
  both served by `app/Filament/Auth/CustomLoginPage.php`.
- Submit email → button **"Send OTP"** → `sendOtp()` requires the user can access the `app`
  or `client` panel, generates a 6-digit code, **synchronously caches** it via
  `OneTimePassword::put()` as `otp_{email}`, and sends `OneTimePasswordNotification` (a
  `ShouldQueue` mailable). The OTP field unhides; the button relabels to **"Login"**.
- Submit the code → `OneTimePassword::get()` compares the cached value → logs the user in.
  Admin lands on the Dashboard (`/app`); client lands on the search/scans page (`/client/...`).
- Form inputs use the `data` statePath: labels are **"Email"** and **"OTP"**.
- The code is in the cache the instant "Send OTP" returns — so the cache is a guaranteed
  fallback even if the log line never appears.

## Inputs

- `EMAIL` — the account to log in as. If omitted, defaults to user id 1 (the seeded admin).
- `PANEL` — `app` (default) or `client`.

## Step 1 — Resolve the environment

```bash
WORKTREE_PATH=$(git rev-parse --show-toplevel)
case "$WORKTREE_PATH" in
  */.claude/worktrees/*) MODE=worktree ;;
  */.cursor/worktrees/*) MODE=worktree ;;
  *)                     MODE=checkout ;;
esac
ENV_FILE="$WORKTREE_PATH/.env"
LOG_FILE="$WORKTREE_PATH/storage/logs/laravel.log"
APP_URL=$(grep -E '^APP_URL=' "$ENV_FILE" | cut -d= -f2- | tr -d '"')
PANEL=${PANEL:-app}
```

If `APP_URL` is empty, stop with a clear message:
- worktree → "worktree isn't provisioned; restart the Claude Code session and retry",
- checkout → "set APP_URL in .env (e.g. https://platform.test) and retry".

## Step 2 — Resolve the target user

If `EMAIL` is unset, resolve id 1's email:

```bash
EMAIL=${EMAIL:-$(php artisan tinker --execute="echo optional(\App\Models\User::find(1))->email;" | tr -d '[:space:]')}
```

Fail fast (before touching `.env`) if the user is missing or can't access the chosen panel —
this mirrors `sendOtp()` and avoids a confusing "Account Not Found" on the form:

```bash
php artisan tinker --execute="
\$u=\App\Models\User::where('email','$EMAIL')->first();
echo \$u && \$u->canAccessPanel(\Filament\Facades\Filament::getPanel('$PANEL')) ? 'OK' : 'NO';
"
```

If this prints `NO`, stop and report which user/panel failed.

## Step 3 — Switch mail → `log` and queue → `sync` (with safe restore)

Helpers (update-if-present, else append):

```bash
get_env(){ grep -E "^$1=" "$ENV_FILE" | head -n1 | cut -d= -f2- | tr -d '"'; }
set_env(){
  if grep -qE "^$1=" "$ENV_FILE"; then
    sed -i.bak -E "s|^$1=.*|$1=$2|" "$ENV_FILE" && rm -f "$ENV_FILE.bak"
  else
    printf '\n%s=%s\n' "$1" "$2" >> "$ENV_FILE"
  fi
}

ORIG_MAIL=$(get_env MAIL_MAILER)
ORIG_QUEUE=$(get_env QUEUE_CONNECTION)
set_env MAIL_MAILER log
set_env QUEUE_CONNECTION sync
php artisan config:clear
```

`QUEUE_CONNECTION=sync` makes the `ShouldQueue` mailable render in-request, so the OTP
reliably lands in the log.

- **Checkout mode:** the dev's real `.env` uses `smtp`/mailtrap — you MUST restore
  `MAIL_MAILER`/`QUEUE_CONNECTION` in Step 8 (and on any failure/abort). Keep `ORIG_MAIL`
  and `ORIG_QUEUE` for that.
- **Worktree mode:** `.env` is gitignored and disposable; restore anyway for cleanliness.

## Step 4 — Drive the form to send the OTP

```bash
export AGENT_BROWSER_SESSION=otp-login
agent-browser open "$APP_URL/$PANEL/login"
agent-browser find label "Email" fill "$EMAIL"
LOG_OFFSET=$(wc -c < "$LOG_FILE" 2>/dev/null || echo 0)   # mark log position
agent-browser find role button click --name "Send OTP"
agent-browser wait --load networkidle                      # let the Livewire round-trip settle
agent-browser snapshot -i                                  # expect the OTP field; or read a validation error
```

If the snapshot shows no OTP field, look for an inline error ("Account Not Found" /
"Email is invalid"), capture it, restore `.env` (Step 8), and stop.

## Step 5 — Retrieve the OTP (log primary, cache fallback)

Primary — read only the new bytes of the log and pull the 6-digit code:

```bash
OTP=$(tail -c +"$((LOG_OFFSET + 1))" "$LOG_FILE" 2>/dev/null \
  | grep -oE 'otp_code"[^>]*>[[:space:]]*[0-9]{6}' | grep -oE '[0-9]{6}' | head -n1)
```

(The laravel-boost MCP `read-log-entries` tool is an alternative reader.)

Fallback — read the cached code directly via the app's own accessor (stays correct if the
key scheme changes):

```bash
if ! printf '%s' "$OTP" | grep -qE '^[0-9]{6}$'; then
  OTP=$(php artisan tinker --execute="
echo optional(\App\Filament\Auth\OneTimePassword::get(\App\Models\User::where('email','$EMAIL')->first()))['otp'];
" | tr -dc '0-9')
fi
```

Validate `OTP` matches `^[0-9]{6}$` before continuing. Remember which source produced it
(log vs cache) for the final report.

## Step 6 — Enter the OTP and finish login

Refs go stale after the Livewire re-render — use `find`, don't reuse old `@eN` refs:

```bash
agent-browser find label "OTP" fill "$OTP"
agent-browser find role button click --name "Login"
agent-browser wait --load networkidle
agent-browser get url        # expect /app (Dashboard) or /client/... — NOT /app/login
agent-browser snapshot -i    # confirm the authenticated Filament shell (sidebar nav)
```

## Step 7 — Verify + report

- Success = `get url` left `/login` and the snapshot shows the panel shell.
- On failure capture `agent-browser console`, `agent-browser errors`, and any OTP-field
  validation text ("Invalid One Time Password").
- Report: the logged-in URL, the user/panel, and which OTP source was used.

## Step 8 — Cleanup (always)

```bash
set_env MAIL_MAILER "${ORIG_MAIL:-smtp}"
set_env QUEUE_CONNECTION "${ORIG_QUEUE:-sync}"
php artisan config:clear
```

Run this even on failure/abort (checkout mode especially). Leave the browser session open by
default so the caller can keep driving it; `agent-browser close` tears it down.

## agent-browser crib (the commands this skill uses)

```bash
export AGENT_BROWSER_SESSION=otp-login   # one isolated browser for the whole run
agent-browser open <url>                 # navigate (also starts the session)
agent-browser find label "Email" fill "x@y.com"   # by <label>; also: find role button click --name "Login"
agent-browser snapshot -i                # interactive elements with @eN refs (re-snapshot after each change)
agent-browser wait --load networkidle    # right wait for Livewire/Filament round-trips
agent-browser wait --text "..."          # until expected text appears
agent-browser get url|title              # read state without re-snapshotting
agent-browser console / agent-browser errors      # JS logs / uncaught errors on failure
agent-browser close                      # tear down (optional — left open by default)
```

Refs (`@eN`) go stale on every navigation, form submit, or Livewire re-render — re-`find`
or re-`snapshot` rather than reusing them. Full reference: `agent-browser --help`.

## Gotchas

- `QUEUE_CONNECTION=sync` is what makes the log read reliable; the cache fallback covers edge
  cases regardless.
- Refs go stale after each Livewire submit — always re-`find` for the OTP field and Login
  button.
- Login is rate-limited to 5 attempts; don't loop blindly on a bad OTP.
- `php artisan config:clear` is required after any `.env` edit (and again after restore).
- On the main checkout, ALWAYS restore both `MAIL_MAILER` and `QUEUE_CONNECTION` — the real
  `.env` points at smtp/mailtrap.
