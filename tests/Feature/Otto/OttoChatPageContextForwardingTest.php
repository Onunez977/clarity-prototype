<?php

namespace Tests\Feature\Otto;

use App\Ai\PageContext;
use App\Jobs\RunOttoChat;
use App\Models\User;
use App\Services\Otto\OttoConversation;
use App\Support\Otto\InChatSearchIntent;
use App\Support\Otto\OttoAccess;
use Illuminate\Support\Facades\Bus;
use ReflectionMethod;
use Tests\TestCase;

class OttoChatPageContextForwardingTest extends TestCase
{
    private function ottoUser(): User
    {
        $user = $this->createUser(permissions: OttoAccess::PERMISSION);
        $this->setOrgProTier($user->org);

        return $user->fresh()->load('org.meta');
    }

    public function test_send_forwards_the_page_context_payload_to_the_job(): void
    {
        Bus::fake();

        $user = $this->ottoUser();
        $this->actingAs($user);

        $page = ['pageType' => 'scan-search', 'description' => 'Scans', 'state' => ['plate' => 'ABC*']];

        $this->postJson(route('otto.disclaimer'))->assertSuccessful();
        $this->postJson(route('otto.conversations.store'), [
            'message' => 'find ABC*',
            'page_context' => $page,
        ])->assertSuccessful();

        Bus::assertDispatched(RunOttoChat::class, function (RunOttoChat $job): bool {
            return $job->pageContextPayload['pageType'] === 'scan-search'
                && $job->pageContextPayload['state']['plate'] === 'ABC*';
        });
    }

    public function test_send_without_page_context_forwards_null(): void
    {
        Bus::fake();

        $user = $this->ottoUser();
        $this->actingAs($user);

        $this->postJson(route('otto.disclaimer'))->assertSuccessful();
        $this->postJson(route('otto.conversations.store'), [
            'message' => 'hello',
        ])->assertSuccessful();

        Bus::assertDispatched(RunOttoChat::class, fn (RunOttoChat $job): bool => $job->pageContextPayload === null);
    }

    public function test_search_instead_strips_page_context(): void
    {
        Bus::fake();

        $user = $this->ottoUser();
        $this->actingAs($user);

        $page = ['pageType' => 'scan-search', 'description' => 'Scans', 'state' => []];

        $this->postJson(route('otto.disclaimer'))->assertSuccessful();
        $this->postJson(route('otto.conversations.store'), [
            'message' => OttoConversation::SEARCH_INSTEAD_PROMPT_FALLBACK,
            'page_context' => $page,
        ])->assertSuccessful();

        Bus::assertDispatched(RunOttoChat::class, fn (RunOttoChat $job): bool => $job->pageContextPayload === null);
    }

    public function test_in_chat_search_intent_strips_page_context_on_scans(): void
    {
        Bus::fake();

        $user = $this->ottoUser();
        $this->actingAs($user);

        $page = ['pageType' => 'scan-search', 'description' => 'Scans', 'state' => []];

        $this->postJson(route('otto.disclaimer'))->assertSuccessful();
        $this->postJson(route('otto.conversations.store'), [
            'message' => 'search yourself for plate ABC123',
            'page_context' => $page,
        ])->assertSuccessful();

        Bus::assertDispatched(RunOttoChat::class, fn (RunOttoChat $job): bool => $job->pageContextPayload === null);
        $this->assertTrue(InChatSearchIntent::shouldStripPageContext('search yourself for plate ABC123', $page));
    }

    public function test_ordinary_plate_ask_keeps_page_context(): void
    {
        Bus::fake();

        $user = $this->ottoUser();
        $this->actingAs($user);

        $page = ['pageType' => 'scan-search', 'description' => 'Scans', 'state' => []];

        $this->postJson(route('otto.disclaimer'))->assertSuccessful();
        $this->postJson(route('otto.conversations.store'), [
            'message' => 'search for plate ABC123',
            'page_context' => $page,
        ])->assertSuccessful();

        Bus::assertDispatched(RunOttoChat::class, function (RunOttoChat $job): bool {
            return ($job->pageContextPayload['pageType'] ?? null) === 'scan-search';
        });
    }

    public function test_the_job_hydrates_a_page_context_from_its_payload(): void
    {
        $user = $this->createUser();

        $job = new RunOttoChat($user, 'hi', 'conv-1', ['pageType' => 'scan-search', 'state' => ['plate' => 'XYZ']]);
        $context = $this->invokePageContext($job);

        $this->assertInstanceOf(PageContext::class, $context);
        $this->assertSame('scan-search', $context->pageType);
        $this->assertSame(['plate' => 'XYZ'], $context->state);

        $nullJob = new RunOttoChat($user, 'hi', 'conv-1', null);
        $this->assertNull($this->invokePageContext($nullJob));
    }

    public function test_path_derived_fallbacks_normalize_on_the_job(): void
    {
        $user = $this->createUser();

        $job = new RunOttoChat($user, 'hi', 'conv-path', [
            'pageType' => '/app/scans',
            'description' => 'Scans',
            'state' => [],
        ]);

        $this->assertSame('scan-search', $this->invokePageContext($job)?->pageType);
        $this->assertSame('hit-search', PageContext::fromClient(['pageType' => '/app/hits'])?->pageType);
        $this->assertSame('hotlist-search', PageContext::fromClient(['pageType' => '/app/hotlists'])?->pageType);
        $this->assertSame('scout-map', PageContext::fromClient(['pageType' => '/scout/org-map'])?->pageType);
        $this->assertSame('scout-map', PageContext::fromClient(['pageType' => 'scout-map', 'description' => 'Org Map'])?->pageType);
    }

    private function invokePageContext(RunOttoChat $job): ?PageContext
    {
        $method = new ReflectionMethod(RunOttoChat::class, 'pageContext');
        $method->setAccessible(true);

        return $method->invoke($job);
    }
}
