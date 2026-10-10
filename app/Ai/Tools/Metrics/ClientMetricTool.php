<?php

declare(strict_types=1);

namespace App\Ai\Tools\Metrics;

use App\Enums\FeatureEnum;
use App\Filament\Widgets\Client\Concerns\ClientStatWidget;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * One client-analytics stat widget. Pass a client only when the widget is for one client.
 */
abstract class ClientMetricTool extends WidgetMetricTool
{
    /**
     * @return class-string<ClientStatWidget>
     */
    abstract protected function widget(): string;

    protected function clientAccepted(): bool
    {
        return true;
    }

    protected function clientRequired(): bool
    {
        return false;
    }

    protected function widgetFeature(): ?FeatureEnum
    {
        return null;
    }

    protected function note(): ?string
    {
        return null;
    }

    /**
     * @return list<string>
     */
    protected function periods(bool $forClient): array
    {
        return ['this_month', 'ytd', 'last_30d', 'last_90d'];
    }

    public function description(): Stringable|string
    {
        $name = $this->metrics->clientWidgetLabel($this->widget());
        $who = $this->clientRequired()
            ? ' for one client. Pass client_name or client_id.'
            : ($this->clientAccepted()
                ? ' Pass client_name or client_id for one client, or omit both for every client.'
                : ' This is the total across clients.');

        $period = $this->periods(true) === []
            ? ''
            : ' Optional period: '.$this->periodDescription().'. Omit for this month.';

        $note = $this->note() !== null ? ' '.$this->note() : '';

        return 'Read '.$name.'.'.$who.$period.$note.' Return the number only when this user can see it. If it is part of Clarity Pro and this organization does not have it, relay the account-manager message and do not invent a number. A role denial is not an upgrade.';
    }

    public function schema(JsonSchema $schema): array
    {
        $fields = [];

        if ($this->periods(true) !== [] || $this->periods(false) !== []) {
            $fields['period'] = $schema->string()->description($this->periodDescription().'. Omit for this month.');
        }

        if ($this->clientAccepted()) {
            $fields['client_name'] = $schema->string()->description($this->clientRequired()
                ? 'Client name. Required. Partial names are ok.'
                : 'Client name for one client. Omit for every client. Partial names are ok.');
            $fields['client_id'] = $schema->integer()->description('Client organization id when already known. Must belong to this organization.');
        }

        return $fields;
    }

    protected function read(Request $request): string
    {
        $clientId = $request['client_id'] ?? null;
        $clientName = isset($request['client_name']) ? (string) $request['client_name'] : null;
        $hasClient = $this->clientAccepted() && (
            (is_numeric($clientId) && (int) $clientId > 0) || (is_string($clientName) && trim($clientName) !== '')
        );

        return $this->metrics->clientWidget(
            user: $this->user,
            widgetClass: $this->widget(),
            period: isset($request['period']) ? (string) $request['period'] : null,
            clientId: is_numeric($clientId) ? (int) $clientId : null,
            clientName: $clientName,
            clientAccepted: $this->clientAccepted(),
            clientRequired: $this->clientRequired(),
            periods: $this->periods($hasClient || $this->clientRequired()),
            feature: $this->widgetFeature(),
            note: $this->note(),
        );
    }

    private function periodDescription(): string
    {
        $forClient = $this->periods(true);
        $forOrg = $this->periods(false);

        if ($forClient === $forOrg) {
            return $this->phraseList($forClient);
        }

        return $this->phraseList($forOrg).'. One client also allows '.$this->phraseList(array_values(array_diff($forClient, $forOrg)));
    }

    /**
     * @param  list<string>  $periods
     */
    private function phraseList(array $periods): string
    {
        $phrases = array_map(fn (string $period): string => match ($period) {
            'today' => 'today',
            'last_7d' => 'last 7 days',
            'this_month' => 'this month',
            'last_month' => 'last month',
            'ytd' => 'year to date',
            'last_30d' => 'last 30 days',
            'last_90d' => 'last 90 days',
            default => $period,
        }, $periods);

        return implode(', ', $phrases);
    }
}
