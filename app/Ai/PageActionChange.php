<?php

namespace App\Ai;

/**
 * One granular change within a proposed {@see PageAction} — e.g. "clear the plate
 * filter" or "set the time range to last 7 days". A page action bundles one or more
 * of these so Otto can change several parameters at once while the user approves
 * them all, or picks individual ones, before anything runs.
 *
 * {@see $field} is the payload key the page adapter applies; {@see $value} is what
 * it is set to (an empty string / array CLEARS that filter — the adapter keys on
 * the field's presence, so a cleared field is still an explicit applied change).
 */
class PageActionChange
{
    public function __construct(
        public string $field,
        public string $label,
        public string $summary,
        public mixed $value,
    ) {}

    /**
     * @return array{field: string, label: string, summary: string, value: mixed}
     */
    public function toArray(): array
    {
        return [
            'field' => $this->field,
            'label' => $this->label,
            'summary' => $this->summary,
            'value' => $this->value,
        ];
    }
}
