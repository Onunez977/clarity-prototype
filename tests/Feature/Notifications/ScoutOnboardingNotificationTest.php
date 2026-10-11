<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\ScoutOnboardingNotification;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class ScoutOnboardingNotificationTest extends TestCase
{
    private function renderMail(User $user): string
    {
        $message = (new ScoutOnboardingNotification)->toMail($user);

        return (string) app(Markdown::class)->render('notifications::email', $message->data());
    }

    #[Group('notifications')]
    public function test_sign_off_renders_a_real_line_break_not_a_literal_br(): void
    {
        $html = $this->renderMail(User::factory()->create());

        $this->assertStringNotContainsString('&lt;br&gt;', $html);
        $this->assertStringContainsString('Thanks,<br>', $html);
        $this->assertStringContainsString('Insight LPR Team', $html);
    }

    #[Group('notifications')]
    public function test_email_subject_action_and_expiry_are_correct(): void
    {
        config()->set('app.experimental.clarity_scout', true);

        $user = User::factory()->create();
        $message = (new ScoutOnboardingNotification)->toMail($user);
        $html = (string) app(Markdown::class)->render('notifications::email', $message->data());

        $this->assertSame('Welcome to Clarity Scout', $message->subject);
        $this->assertStringContainsString('Set Your Password', $html);
        $this->assertStringContainsString('3 days', $html);
    }

    #[Group('notifications')]
    #[Group('clarity-scout')]
    public function test_action_url_uses_a_relative_signature_that_survives_http_requests(): void
    {
        config()->set('app.experimental.clarity_scout', true);
        config()->set('app.url', 'https://clarity.insightlpr.io');

        $user = User::factory()->create();
        $message = (new ScoutOnboardingNotification)->toMail($user);
        $url = $message->actionUrl;

        $this->assertStringStartsWith('https://clarity.insightlpr.io/scout/set-password/', $url);

        $parts = parse_url($url);
        parse_str($parts['query'] ?? '', $query);
        $this->assertArrayNotHasKey('nonce', $query);
        $this->assertArrayHasKey('expires', $query);
        $this->assertArrayHasKey('signature', $query);

        $httpRequest = Request::create('http://clarity.insightlpr.io'.$parts['path'].'?'.$parts['query'], 'GET');

        $this->assertTrue($httpRequest->hasValidSignature(absolute: false));
        $this->assertFalse($httpRequest->hasValidSignature(absolute: true));
    }
}
