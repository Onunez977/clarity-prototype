<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ScoutAnalyticsEvent;
use App\Enums\ScoutAnalyticsPage;
use App\Facades\Analytics;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class ClarityScoutNotificationsController extends Controller
{
    /**
     * Return the authenticated driver's recent notifications (newest first,
     * capped) plus the current unread count for the bell badge.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = $this->scoutNotifications($user)
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (DatabaseNotification $n) => $this->toArray($n))
            ->all();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $this->scoutNotifications($user)->whereNull('read_at')->count(),
        ]);
    }

    public function markRead(Request $request, string $notification): JsonResponse
    {
        $user = $request->user();
        $record = $this->scoutNotifications($user)->findOrFail($notification);
        $record->markAsRead();

        Analytics::track(ScoutAnalyticsEvent::NotificationRead->value, $user, ['action' => 'mark-read', 'id' => $record->id], page: ScoutAnalyticsPage::Notifications->value);

        return response()->json([
            'notification' => $this->toArray($record->fresh()),
            'unread_count' => $this->scoutNotifications($user)->whereNull('read_at')->count(),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->scoutNotifications($user)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        Analytics::track(ScoutAnalyticsEvent::NotificationsReadAll->value, $user, ['action' => 'mark-all-read'], page: ScoutAnalyticsPage::Notifications->value);

        return response()->json(['unread_count' => 0]);
    }

    /**
     * Scope to Scout-owned notifications only. Scout writes a `scout.`-prefixed
     * type (see ScoutNotifier), so this excludes admin-panel Filament
     * notifications that share the same User notifiable.
     */
    private function scoutNotifications(User $user): MorphMany
    {
        return $user->notifications()->where('type', 'like', 'scout.%');
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(DatabaseNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'kind' => $notification->data['kind'] ?? null,
            'title' => $notification->data['title'] ?? null,
            'body' => $notification->data['body'] ?? null,
            'zone_id' => $notification->data['zone_id'] ?? null,
            'zone_name' => $notification->data['zone_name'] ?? null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
