<?php

namespace App\Dto;

use InsightLPR\BlackAdam\Enum\ScanMetaType;

class ScanMetaDataDto
{
    public function __construct(
        public object $vehicle,
        public ?bool $notPlate,
        public ?string $address,
        public ?string $simpleAddress,
        public ?string $plateState,
        public ?string $disposition,
        public ?bool $is_daytime,
    ) {
        //
    }

    public static function fromArray(array $data): self
    {
        $vehicle = [
            'type' => $data[ScanMetaType::VehicleType->value] ?? null,
            'color' => $data[ScanMetaType::VehicleColor->value] ?? null,
            'make' => $data[ScanMetaType::VehicleMake->value] ?? null,
        ];

        $dataNotPlate = $data['notPlate'] ?? $data[ScanMetaType::MLPlateNotPlate->value] ?? false;

        $notPlate = false;
        if ($dataNotPlate !== null && (\strtolower((string) $dataNotPlate) === 'true' || $dataNotPlate === true)) {
            $notPlate = true;
        }

        return new self(
            vehicle: (object) ($data['vehicle'] ?? $vehicle),
            notPlate: $notPlate,
            address: $data['address'] ?? $data[ScanMetaType::FormattedAddress->value] ?? null,
            simpleAddress: $data['simpleAddress'] ?? $data[7] ?? null,
            plateState: $data['plateState'] ?? $data[ScanMetaType::MLPlateState->value] ?? null,
            disposition: $data['disposition'] ?? null,
            is_daytime: $data['is_daytime'] ?? null,
        );
    }

    public function toJson(): string
    {
        return collect($this->toFormattedArray())
            ->filter(fn ($value) => $value !== null)
            ->toJson();
    }

    public function toFormattedArray(bool $withTitles = false): array
    {
        $values = [];

        if ($this->vehicle) {
            $values[
                $withTitles ? ScanMetaType::VehicleType->title() : ScanMetaType::VehicleType->value
            ] = $this->vehicle->type ?? null;

            $values[
                $withTitles ? ScanMetaType::VehicleColor->title() : ScanMetaType::VehicleColor->value
            ] = $this->vehicle->color ?? null;

            $values[
                $withTitles ? ScanMetaType::VehicleMake->title() : ScanMetaType::VehicleMake->value
            ] = $this->vehicle->make ?? null;
        }

        $notPlate = false;
        if ($this->notPlate !== null && (\strtolower((string) $this->notPlate) === 'true' || $this->notPlate === true)) {
            $notPlate = true;
        }

        $values[
            $withTitles ? ScanMetaType::MLPlateNotPlate->title() : ScanMetaType::MLPlateNotPlate->value
        ] = $notPlate;

        if ($this->plateState !== null) {
            $values[
                $withTitles ? ScanMetaType::MLPlateState->title() : ScanMetaType::MLPlateState->value
            ] = $this->plateState;
        }

        if ($this->address !== null) {
            $values[
                $withTitles ? ScanMetaType::FormattedAddress->title() : ScanMetaType::FormattedAddress->value
            ] = $this->address;
        }

        if ($this->simpleAddress !== null) {
            $values[
                $withTitles ? 'Simple Address' : 8
            ] = $this->simpleAddress;
        }

        if ($this->disposition !== null) {
            $values[
                $withTitles ? 'Disposition' : 'disposition'
            ] = $this->disposition;
        }

        if ($this->is_daytime !== null) {
            $values[
            $withTitles ? 'Is Daytime' : 'is_daytime'
            ] = $this->is_daytime;
        }

        return $values;
    }
}
