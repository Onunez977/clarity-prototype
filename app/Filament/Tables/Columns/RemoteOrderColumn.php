<?php

namespace App\Filament\Tables\Columns;

use Closure;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Str;
use InsightLPR\BlackAdam\Datatype\RemoteOrderId;
use InsightLPR\BlackAdam\Enum\IntegrationEnum;

class RemoteOrderColumn extends TextColumn
{
    protected mixed $integrationId = null;

    public function setUp(): void
    {
        parent::setUp();

        $this->formatStateUsing(function (): string {
            $integrationId = $this->getIntegrationId();

            return $this->remoteLink($integrationId, $this->getState()) !== null
                ? $this->remoteName($integrationId)
                : $this->getState();
        });

        $this->url(function (): mixed {
            $integrationId = $this->getIntegrationId();

            return $this->remoteLink($integrationId, $this->getState()) ?? false;
        });
    }

    public function getDescriptionBelow(): string|Htmlable|null
    {
        $integrationId = $this->getIntegrationId();

        return $this->remoteLink($integrationId, $this->getState())
            ? $this->remoteName($integrationId)
            : null;
    }

    protected function remoteName($integrationId): string|array
    {
        return Str::replace('_', ' ', IntegrationEnum::tryFrom($integrationId)?->name);
    }

    protected function remoteLink($integrationId, $remoteId): ?string
    {
        return IntegrationEnum::tryFrom($integrationId)?->remoteLink(new RemoteOrderId($remoteId));
    }

    public function setIntegrationId(Closure $integrationId): self
    {
        $this->integrationId = $integrationId;

        return $this;
    }

    public function getIntegrationId(): mixed
    {
        return $this->evaluate($this->integrationId);
    }
}
