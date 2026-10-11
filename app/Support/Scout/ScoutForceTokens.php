<?php

declare(strict_types=1);

namespace App\Support\Scout;

use App\Http\Controllers\ClarityScoutForceLoginController;
use App\Models\User;
use Illuminate\Support\Facades\Session;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Mint and revoke the Scout force-login bearer token that staff use to open the
 * driver PWA as a driver ({@see ClarityScoutForceLoginController}).
 *
 * The token is a gate bypass — `ClarityScoutMiddleware` honours the literal
 * `clarity-scout-force` ability — so it is bound by a short TTL, pruned per
 * driver, and revoked when the impersonation that minted it ends.
 *
 * Minted ids are tracked in the impersonator's session because the token
 * belongs to the DRIVER, not to the staff user holding the session: revoking
 * via `$request->user()->tokens()` reaches the wrong account entirely.
 *
 * Session shape is a driver-keyed map (`driverId => list of token ids`) so
 * prune-removed ids stop lingering and a targeted revoke can address one
 * driver. Pre-deploy flat lists are normalised on read. Revoke-all (default)
 * remains the correct exit/leave/re-entry behaviour — do not spare other
 * drivers' tokens on exit or gate-bypassing credentials outlive the session.
 */
class ScoutForceTokens
{
    public const NAME = 'clarity-scout-force-token';

    public const TTL_MINUTES = 60;

    /**
     * Force tokens left live per driver. A single click can produce more than
     * one GET (browser prefetch, a double-click, a reload), and each GET mints
     * a token — pruning to exactly one would revoke the token embedded in the
     * page the staff user is actually looking at, bouncing it to the login
     * screen on its first poll.
     */
    private const KEEP_PER_DRIVER = 3;

    private const SESSION_KEY = 'clarity_scout_force_token_ids';

    public static function mintFor(User $driver): string
    {
        $token = $driver->createToken(
            self::NAME,
            ['*', 'clarity-scout-force'],
            now()->addMinutes(self::TTL_MINUTES),
        );

        $tokenId = $token->accessToken->getKey();

        self::prune($driver);

        $tracked = self::normalise(Session::get(self::SESSION_KEY, []));
        $driverKey = (string) $driver->getKey();

        $liveIds = $driver->tokens()
            ->where('name', self::NAME)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        // Keep the session bucket aligned with tokens that survived prune,
        // then ensure the freshly minted id is present (it is always live).
        $bucket = array_values(array_unique(array_map(
            'intval',
            array_merge(
                array_intersect($tracked[$driverKey] ?? [], $liveIds),
                [$tokenId],
            ),
        )));

        $tracked[$driverKey] = $bucket;
        Session::put(self::SESSION_KEY, $tracked);

        return $token->plainTextToken;
    }

    /**
     * Revoke force tokens minted during the current impersonation session.
     *
     * @param  User|null  $driver  When set, revoke only that driver's tracked
     *                             tokens. Null (default) revokes every tracked
     *                             id — the intended leave / exit / re-entry
     *                             semantics at all existing call sites.
     */
    public static function revokeTracked(?User $driver = null): void
    {
        $tracked = self::normalise(Session::get(self::SESSION_KEY, []));

        if ($tracked === []) {
            Session::forget(self::SESSION_KEY);

            return;
        }

        if ($driver === null) {
            $ids = self::flatten($tracked);
            Session::forget(self::SESSION_KEY);
        } else {
            $driverKey = (string) $driver->getKey();
            $ids = $tracked[$driverKey] ?? [];
            unset($tracked[$driverKey]);

            if ($tracked === []) {
                Session::forget(self::SESSION_KEY);
            } else {
                Session::put(self::SESSION_KEY, $tracked);
            }
        }

        if ($ids === []) {
            return;
        }

        PersonalAccessToken::query()->whereIn('id', $ids)->delete();
    }

    /**
     * Drop this driver's expired force tokens plus anything older than the
     * newest {@see self::KEEP_PER_DRIVER}.
     */
    private static function prune(User $driver): void
    {
        $tokens = $driver->tokens()
            ->where('name', self::NAME)
            ->orderByDesc('id')
            ->get(['id', 'expires_at']);

        $stale = $tokens->slice(self::KEEP_PER_DRIVER)
            ->merge($tokens->filter(fn ($token) => $token->expires_at?->isPast() ?? false))
            ->pluck('id')
            ->unique();

        if ($stale->isEmpty()) {
            return;
        }

        $driver->tokens()->whereIn('id', $stale)->delete();
    }

    /**
     * Map a pre-deploy flat list of token ids onto a single synthetic bucket,
     * or pass through an already driver-keyed map.
     *
     * @return array<string, list<int>>
     */
    private static function normalise(mixed $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            return [];
        }

        // Flat list (legacy): [1, 2, 3] — all ints / numeric strings, sequential keys.
        if (array_is_list($raw)) {
            $ids = array_values(array_unique(array_map('intval', $raw)));

            return $ids === [] ? [] : ['_legacy' => $ids];
        }

        $out = [];

        foreach ($raw as $driverKey => $ids) {
            if (! is_array($ids)) {
                continue;
            }

            $clean = array_values(array_unique(array_map('intval', $ids)));

            if ($clean !== []) {
                $out[(string) $driverKey] = $clean;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, list<int>>  $tracked
     * @return list<int>
     */
    private static function flatten(array $tracked): array
    {
        $ids = [];

        foreach ($tracked as $bucket) {
            foreach ($bucket as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }
}
