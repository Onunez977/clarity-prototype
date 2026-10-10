<?php

namespace App\Filament\Tables\Columns;

use Carbon\Carbon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class DateTimeColumn extends TextColumn
{
    protected bool $wrapped = true;

    protected bool $showIcon = false;

    public function setUp(): void
    {
        parent::setUp();

        $this->timezone = auth()->user()->timezone;

        $this->date(config('app.date_format'), $this->timezone);

        $this->formatStateUsing(function (): string {
            $date = $this->getRecordValue()?->setTimezone(auth()->user()->timezone);

            if (! $date) {
                return 'N/A';
            }

            return $this->wrapped
                ? $date->format('M j, Y').'<br/>'.$date->format('h:i A T')
                : $date->format(config('app.date_format'));
        });

        $this->html(fn () => $this->wrapped);
    }

    protected function getRecordValue(): mixed
    {
        $value = $this->getRecord()->{Str::replace('.', '->', $this->getName())};

        if (\gettype($value) === 'string') {
            $value = Carbon::make($value);
        }

        return $value;
    }

    protected function isNight($timestamp): bool
    {
        $hour = $timestamp->format('H');

        return ! ($hour > 6 && $hour < 18);
    }

    public function getDescriptionBelow(): string|Htmlable|null
    {
        return new HtmlString('<span class="flex gap-1">'.$this->getIconForDescription().' '.$this->getRecordValue()?->diffForHumans().'</span>');
    }

    public function getTooltip(mixed $state = null): ?string
    {
        $value = $this->getRecordValue() ?? $state;

        if ($value && $this->showIcon) {
            return $this->isNight($value) ? 'Night Scan' : 'Day Scan';
        }

        return null;
    }

    public function getIconForDescription(): string
    {
        $value = $this->getRecordValue();

        if ($value && $this->showIcon) {
            return $this->isNight($value)
                ? '<svg class="fi-ta-text-item-icon h-5 w-5 text-gray-400 dark:text-gray-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" data-slot="icon"><path fill-rule="evenodd" d="M7.455 2.004a.75.75 0 0 1 .26.77 7 7 0 0 0 9.958 7.967.75.75 0 0 1 1.067.853A8.5 8.5 0 1 1 6.647 1.921a.75.75 0 0 1 .808.083Z" clip-rule="evenodd"></path></svg>'
                : '<svg style="--c-500:var(--warning-500);" class="fi-ta-text-item-icon h-5 w-5 text-custom-500" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" data-slot="icon"><path d="M10 2a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 10 2ZM10 15a.75.75 0 0 1 .75.75v1.5a.75.75 0 0 1-1.5 0v-1.5A.75.75 0 0 1 10 15ZM10 7a3 3 0 1 0 0 6 3 3 0 0 0 0-6ZM15.657 5.404a.75.75 0 1 0-1.06-1.06l-1.061 1.06a.75.75 0 0 0 1.06 1.06l1.06-1.06ZM6.464 14.596a.75.75 0 1 0-1.06-1.06l-1.06 1.06a.75.75 0 0 0 1.06 1.06l1.06-1.06ZM18 10a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 18 10ZM5 10a.75.75 0 0 1-.75.75h-1.5a.75.75 0 0 1 0-1.5h1.5A.75.75 0 0 1 5 10ZM14.596 15.657a.75.75 0 0 0 1.06-1.06l-1.06-1.061a.75.75 0 1 0-1.06 1.06l1.06 1.06ZM5.404 6.464a.75.75 0 0 0 1.06-1.06l-1.06-1.06a.75.75 0 1 0-1.061 1.06l1.06 1.06Z"></path></svg>';
        }

        return '';
    }

    public function showIcon(bool $show = true): self
    {
        $this->showIcon = $show;

        return $this;
    }

    public function wrapped(bool $wrapped = true): self
    {
        $this->wrapped = $wrapped;

        return $this;
    }
}
