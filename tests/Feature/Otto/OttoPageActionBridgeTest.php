<?php

namespace Tests\Feature\Otto;

use App\Livewire\Concerns\InteractsWithOttoPageActions;
use Livewire\Component;
use Livewire\Livewire;
use Tests\TestCase;

class OttoPageActionBridgeTest extends TestCase
{
    public function test_it_applies_an_action_targeting_this_page(): void
    {
        Livewire::test(FakeOttoPageAdapter::class)
            ->dispatch('otto-page-action', pageType: 'fake-page', name: 'do-thing', payload: ['x' => 1])
            ->assertSet('applied', [['name' => 'do-thing', 'payload' => ['x' => 1]]]);
    }

    public function test_it_ignores_an_action_meant_for_a_different_page(): void
    {
        Livewire::test(FakeOttoPageAdapter::class)
            ->dispatch('otto-page-action', pageType: 'some-other-page', name: 'do-thing', payload: ['x' => 1])
            ->assertSet('applied', []);
    }

    public function test_it_refuses_an_action_when_the_user_is_not_authorized(): void
    {
        Livewire::test(FakeOttoPageAdapter::class, ['authorized' => false])
            ->dispatch('otto-page-action', pageType: 'fake-page', name: 'do-thing', payload: ['x' => 1])
            ->assertSet('applied', []);
    }

    public function test_it_advertises_its_page_context_on_render_without_error(): void
    {
        Livewire::test(FakeOttoPageAdapter::class)->assertOk();
    }
}

class FakeOttoPageAdapter extends Component
{
    use InteractsWithOttoPageActions;

    /** @var list<array{name: string, payload: array<string, mixed>}> */
    public array $applied = [];

    public bool $authorized = true;

    public function mount(bool $authorized = true): void
    {
        $this->authorized = $authorized;
    }

    protected function ottoPageType(): string
    {
        return 'fake-page';
    }

    /**
     * @return array<string, mixed>
     */
    protected function ottoPageContextState(): array
    {
        return ['foo' => 'bar'];
    }

    protected function ottoPageActionAuthorized(): bool
    {
        return $this->authorized;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function applyOttoPageAction(string $name, array $payload): void
    {
        $this->applied[] = ['name' => $name, 'payload' => $payload];
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
