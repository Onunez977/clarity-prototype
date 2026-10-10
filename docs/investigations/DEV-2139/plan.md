# DEV-2139 — Plan

**Route:** bug · **Base:** main · **Sizing:** 1 subtask, 2 files (impl + test) — well under the split threshold.

## t1 — Fix Scout onboarding salutation to a markdown hard break + regression test  _(simple)_

**Root cause:** Laravel's default notification template (`email.blade.php`) echoes `{{ $salutation }}`
through `EncodedHtmlString` (`htmlspecialchars`) **before** CommonMark parses it, so the literal
`<br>` in `->salutation('Thanks,<br>Insight LPR Team')` is escaped and shown as visible text.

**Change:** `app/Notifications/ScoutOnboardingNotification.php:39`
```php
->salutation("Thanks,  \nInsight LPR Team");   // two trailing spaces + \n = CommonMark hard break
```
The hard break is native markdown (processed after the escape step) → real line break in **both**
the HTML and plain-text mail parts. (Plain `\n` = unreliable soft break; `HtmlString` breaks the
plain-text fallback — both rejected in prep via tinker.)

**Test:** `tests/Feature/Mail/Scout/ScoutOnboardingNotificationTest.php` (mirrors
`OneTimePasswordNotificationTest.php`) — render the MailMessage and assert:
1. no literal/escaped `<br>` text in the rendered sign-off (AC-1 regression pin);
2. `Thanks,` and `Insight LPR Team` appear as separate lines / a real `<br>` between them;
3. subject `Welcome to Clarity Scout`, the set-password action, and the 7-day expiry text present (AC-2).

**Blast radius:** none (notifications are not in the sensitive globs). No DB/CH/migration/auth impact.
Fix is centralized in the notification, so all 3 dispatch sites benefit.
