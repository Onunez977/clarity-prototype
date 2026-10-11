<?php

declare(strict_types=1);

namespace App\Support\Otto;

use App\Actions\Activity\ActivityFactory;
use App\Models\User;

/**
 * Writes an Otto data search through {@see ActivityFactory} — the same logger
 * Filament filter searches use via `LogFilterActivity`. Not a second logger.
 *
 * Log names are the Otto trackers (`Otto Scans`, `Otto Hits`, …) so
 * `ActivityFactory::getTracker()` resolves `Otto*Search`. Those classes reuse
 * the Filament search wording and say the search was via Otto. Filament filter
 * searches keep the original log names (`Scans Search`, and so on).
 *
 * Horizon has no HTTP request, so the turn stamps the browser session captured
 * at send time under {@see self::SESSION_KEY}. Direct calls fall back to the
 * current request, matching `HasFilterActivityLogging`.
 */
final class SearchActivityLog
{
    public const SCANS = 'Otto Scans';

    public const HITS = 'Otto Hits';

    public const HOTLISTS = 'Otto Hotlists';

    public const ZONES = 'Otto Zones';

    public const SCOUT_DRIVER_POSITIONS = 'Otto Scout driver positions';

    public const SESSION_KEY = 'otto_activity_session';

    /**
     * @param  array<string, mixed>  $criteria
     */
    public static function record(string $logName, User $causer, array $criteria, ?string $conversationId = null): void
    {
        $data = [];

        foreach ($criteria as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $data[$key] = $value;
        }

        $data['via'] = 'otto';

        $conversationId = trim((string) $conversationId);
        if ($conversationId !== '') {
            $data['conversationId'] = $conversationId;
        }

        ActivityFactory::make()->log(
            logName: $logName,
            data: $data,
            causer: $causer,
            session: self::session(),
        );
    }

    /**
     * Snapshot taken on the HTTP send, restored on the `{otto}` worker.
     *
     * @return array{impersonator: mixed, clientIp: mixed, userAgent: mixed}
     */
    public static function captureSession(): array
    {
        return [
            'impersonator' => session()->get('impersonator'),
            'clientIp' => request()->getClientIp(),
            'userAgent' => request()->userAgent(),
        ];
    }

    /**
     * @return array{impersonator: mixed, clientIp: mixed, userAgent: mixed}
     */
    public static function session(): array
    {
        $stamped = session(self::SESSION_KEY);

        if (is_array($stamped)) {
            return [
                'impersonator' => $stamped['impersonator'] ?? null,
                'clientIp' => $stamped['clientIp'] ?? null,
                'userAgent' => $stamped['userAgent'] ?? null,
            ];
        }

        return self::captureSession();
    }
}
