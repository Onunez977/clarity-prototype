<?php

namespace App\Filament\Widgets\Dashboard\Concerns;

use Illuminate\Support\Str;

trait StatHelper
{
    public function cacheKey(string $key, mixed $orgId = null, ?string $class = null): string
    {
        $class = Str::of(\class_basename($class ?? $this))
            ->replace('Stat', '')
            ->snake('-')
            ->toString();

        if (! $orgId && \property_exists($this, 'org')) {
            $orgId = $this->org->id;
        } elseif (! $orgId && \property_exists($this, 'orgId')) {
            $orgId = $this->orgId;
        }

        return $class.':'.$key.':org='.$orgId;
    }
}
