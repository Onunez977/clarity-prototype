# DEV-2183 — Review

## RN 1 (param approach) — 95/100
See git history. Superseded by the simplification below.

## RN 2 (hardcode `>= 3`, param dropped per q2 revision) — 96/100 (gate >= 90 PASS)
In-session adversarial (high effort; rigor review.sh substituted — "Not logged in" under harness).

**Change:** single literal `length(plate) > 3` -> `>= 3` in HotlistAddressGeohashQuery. All
three callers (Scout driver, admin Bird's Eye, non-Scout KitUserPathController) now `>= 3`.

**Scoring findings:** none.
- Injection: N/A — the guard is now a literal `3`, no interpolation (strictly simpler/safer than the param).
- Correctness: identical to dashboard HotlistCount (`length(plate) >= 3`). NULL plate still excluded (NULL >= 3 -> NULL). Only exactly-length-3 plates newly included, on all three surfaces.
- Test integrity: query-level positive assertion (assertContains len3) guards against silent-empty results; length-2 negative pins the lower boundary. Controller tests mirror existing passing union/clip tests.

**Non-scoring:** cache-key of the Scout order layers is unchanged now (no new arg), so no post-deploy recompute churn (an improvement over RN 1).

CI runs the RequiresClickhouse suite on this main-based PR.
