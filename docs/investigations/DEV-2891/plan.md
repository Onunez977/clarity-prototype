# DEV-2891 plan

One subtask (`t1`). Five small app edits enforce a single invariant: `alert_settings` channel values are JSON lists, and the reader survives when they are not. Splitting would duplicate the fixture matrix and still have to run in sequence.

## Changes

| File | Change |
| --- | --- |
| `app/Models/Concerns/HitAlertSettings.php:36-42` | Replace `whereJsonLength` x3 with `orWhereRaw(channelHasContentSql($ch))`. The webhook branch, cache key and TTL are unchanged. |
| `app/Dto/AlertSettingSettingsDto.php` | `toList()` returns `array_values` for arrays and `[]` otherwise. Apply it to types, clients, sms, emails and slack in `fromArray` and `toJson`, and guard a non-array `notifications`. |
| `app/Models/AlertSetting.php:122-136` | Add `->values()` in `removeEmail`/`removeSms`. `removeSms` now reads `routeNotificationForSms()`. |
| `app/Filament/Forms/AlertSettingsForm.php:334-372` | Add `->values()` before `->toArray()` in `formatSms`, `formatEmails` and `formatSlack`. |
| `app/Casts/AlertSettingsCast.php:15` | `get()` passes `[]` when the decoded value is not an array (a stored JSON `null` no longer TypeErrors). |

## Guard SQL

Laravel currently emits `jsonb_array_length(("settings"->'notifications'->'sms')::jsonb) > ?`. That throws with 22023 on an object, a scalar or a JSON null. The replacement for each channel is:

```sql
(jsonb_typeof(settings->'notifications'->'<ch>') in ('array', 'object')
 and settings->'notifications'->'<ch>' not in ('[]'::jsonb, '{}'::jsonb))
```

I checked it on local Postgres with JSON literals:
- It is true for a non-empty array or object.
- It is false for `[]`, `{}`, `null`, a string or a number.
- It is NULL, and therefore excluded, when the key is missing.
- Nothing throws, whatever order Postgres evaluates the AND in.
- There is no `?` in the SQL and no interpolated input.

**Scalar policy:** a non-empty scalar such as `"555"` does not count as configured. The SQL excludes it and the DTO maps it to `[]`, so the form, the stored shape and the alert reader all agree.

## Tests

Filter: `HitAlertSettingsTest|AlertSettingTest|AlertSettingSettingsDtoTest|AlertSettingsCastTest|EmailControllerTest|AlertSettingsFormTest|HitWebhookFanOutTest|HitEventListenerTest`

- **New `tests/Feature/Models/Concerns/HitAlertSettingsTest.php`:** a per-shape matrix across L/S/M hits. Valid, object-with-content and webhook-only rows resolve and are notified. Empty, null, scalar and missing rows are excluded.
- **New `tests/Feature/Models/AlertSettingTest.php`:** `removeEmail` stores an array. `removeSms` shrinks only `sms`. A legacy object row heals on save.
- **Existing DTO, cast, EmailController and form tests:** new cases assert list output.
- **Seeding:** malformed shapes are written to the raw column with `DB::table('alert_settings')->update(...)`. Tests call `Cache::flush()` before each case and `freezeTime()`.

## Excluded

- **Backfill command:** deferred by human decision.
- **Prod inventory:** a pre-merge gate Dalton runs.
- **`@>` subscribed-to queries:** heal once rows are fixed.
- **SubscribedAlertsWidget scope leak:** note it in the PR and ask before filing.

## Risks

- The worktree has no `vendor/`, so run `composer install` before running tests.
- Because the DTO also normalises on read, a scalar `sms` value on an otherwise-valid row now routes SMS to nobody. This is intended.
