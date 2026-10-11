<?php

declare(strict_types=1);

namespace App\Http\Controllers\Otto;

use App\Http\Controllers\Controller;
use App\Http\Requests\Otto\ListOttoConversationsRequest;
use App\Http\Requests\Otto\OttoZoneActionRequest;
use App\Http\Requests\Otto\SendOttoMessageRequest;
use App\Http\Requests\Otto\StoreOttoFeedbackRequest;
use App\Services\Otto\OttoConversation;
use App\Support\Otto\OttoAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(private OttoConversation $conversations) {}

    public function index(ListOttoConversationsRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(OttoAccess::userCanUseOtto($user), 403);

        $active = session('otto_active_conversation');

        return response()->json([
            'conversations' => $this->conversations->list($user, (string) ($request->validated('search') ?? '')),
            'showDisclaimer' => $this->conversations->showDisclaimer(),
            'activeConversationId' => is_string($active) && $active !== '' ? $active : null,
        ]);
    }

    public function store(SendOttoMessageRequest $request): JsonResponse
    {
        $conversationId = $this->conversations->send(
            $request->user(),
            null,
            (string) $request->validated('message'),
            $request->pageContext(),
            session('admin_impersonator'),
        );

        return response()->json(['conversationId' => $conversationId]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json(
            $this->conversations->show($request->user(), $id),
        );
    }

    public function storeMessage(SendOttoMessageRequest $request, string $id): JsonResponse
    {
        $conversationId = $this->conversations->send(
            $request->user(),
            $id,
            (string) $request->validated('message'),
            $request->pageContext(),
            session('admin_impersonator'),
        );

        return response()->json(['conversationId' => $conversationId]);
    }

    public function poll(Request $request, string $id): JsonResponse
    {
        $baseline = $request->input('turn_baseline_assistant_count');

        return response()->json(
            $this->conversations->poll(
                $request->user(),
                $id,
                is_numeric($baseline) ? (int) $baseline : null,
            ),
        );
    }

    public function stop(Request $request, string $id): JsonResponse
    {
        $this->conversations->stop($request->user(), $id);

        return response()->json(['ok' => true]);
    }

    public function reset(Request $request): JsonResponse
    {
        $conversationId = $request->input('conversationId');

        $this->conversations->forgetActiveConversation(
            $request->user(),
            is_string($conversationId) && $conversationId !== '' ? $conversationId : null,
        );

        return response()->json(['ok' => true]);
    }

    public function feedback(StoreOttoFeedbackRequest $request, string $id, string $messageId): JsonResponse
    {
        $rating = $request->validated('rating');

        return response()->json(
            $this->conversations->recordFeedback(
                $request->user(),
                $id,
                $messageId,
                is_bool($rating) ? $rating : null,
                is_string($request->validated('comment')) ? $request->validated('comment') : null,
                is_string($request->validated('surface')) ? $request->validated('surface') : 'chat',
            ),
        );
    }

    public function acceptDisclaimer(Request $request, ?string $id = null): JsonResponse
    {
        $user = $request->user();
        abort_unless(OttoAccess::userCanUseOtto($user), 403);

        if ($id !== null) {
            $this->conversations->requireOwnedConversation($user, $id);
        }

        $this->conversations->acceptDisclaimer();

        return response()->json([
            'ok' => true,
            'showDisclaimer' => false,
        ]);
    }

    public function applyZoneCreate(OttoZoneActionRequest $request, string $id): JsonResponse
    {
        return response()->json(
            $this->conversations->applyZoneCreate($request->user(), $id, $request->payload()),
        );
    }

    public function applyZoneUpdate(OttoZoneActionRequest $request, string $id): JsonResponse
    {
        return response()->json(
            $this->conversations->applyZoneUpdate($request->user(), $id, $request->payload()),
        );
    }

    public function openZoneEditor(OttoZoneActionRequest $request, string $id): JsonResponse
    {
        return response()->json(
            $this->conversations->openZoneEditor($request->user(), $id, $request->payload()),
        );
    }
}
