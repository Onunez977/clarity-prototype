<?php

namespace App\Dto;

use App\Clickhouse\Models\Scan as ClickhouseScan;
use Clickbar\Magellan\Data\Geometries\Point;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use InsightLPR\BlackAdam\Enum\ScanMetaType;
use InsightLPR\BlackAdam\Enum\VehicleImageTypeEnum;

class ScanViewDto
{
    public readonly array $coordinates;

    public function __construct(
        public readonly int $id,
        public readonly string $ocr,
        public readonly ?string $us_state,
        public readonly string $guid,
        public readonly ?string $plateImage,
        public readonly ?string $vehicleImage,
        public readonly ?string $address,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly ?string $disposition,
        public readonly ?Carbon $scanned_at,
        public readonly ?int $kit_org_id,
        public readonly ?string $camera_name = null,
        public readonly ?string $kit_name = null,
        public readonly ?int $same_location_count = null,
    ) {
        $this->coordinates = [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }

    public static function fromScan(Model $scan): self
    {
        $metadata = $scan->metadata ?? null;
        if (\is_string($metadata)) {
            $metadata = \json_decode($metadata);
        }

        return new self(
            id: $scan->id,
            ocr: $scan->ocr,
            us_state: $scan->us_state,
            guid: $scan->guid,
            plateImage: scan_img($scan->guid, VehicleImageTypeEnum::Plate->value),
            vehicleImage: scan_img($scan->guid, VehicleImageTypeEnum::Car->value),
            address: $scan->scanMetaData?->meta->address ?? $metadata?->address ?? $metadata->{ScanMetaType::FormattedAddress->value} ?? null,
            latitude: $scan->geo instanceof Point ? $scan->geo->getLatitude() : ($scan->lat ?? null),
            longitude: $scan->geo instanceof Point ? $scan->geo->getLongitude() : ($scan->lng ?? null),
            disposition: $scan->scanMetaData?->meta->disposition ?? $metadata?->disposition ?? null,
            scanned_at: $scan->scanned_at instanceof Carbon ? $scan->scanned_at : Carbon::parse($scan->scanned_at),
            kit_org_id: $scan->kit_org_id ?? null,
            camera_name: $scan->camera_name ?? null,
            kit_name: $scan->kit_name ?? null,
            same_location_count: $scan->same_location_count ?? null,
        );
    }

    public static function fromClickhouseScan(ClickhouseScan $scan): self
    {
        return new self(
            id: $scan->id,
            ocr: $scan->ocr,
            us_state: $scan->us_state,
            guid: $scan->guid,
            plateImage: scan_img($scan->guid, VehicleImageTypeEnum::Plate->value),
            vehicleImage: scan_img($scan->guid, VehicleImageTypeEnum::Car->value),
            address: $scan->address ?? null,
            latitude: $scan->lat ? (float) $scan->lat : null,
            longitude: $scan->lng ? (float) $scan->lng : null,
            disposition: $scan->disposition ?? null,
            scanned_at: $scan->scanned_at instanceof Carbon ? $scan->scanned_at : Carbon::parse($scan->scanned_at),
            kit_org_id: $scan->kit_org_id ?? null,
            camera_name: $scan->camera_name ?? null,
            kit_name: $scan->kit_name ?? null,
        );
    }

    public static function fromHitRecord(Model $record, ?object $scanMetadata = null): self
    {
        $coords = \json_decode($record->scans_geo ?? '{}')?->coordinates ?? null;
        $latitude = $coords ? (float) $coords[1] : null;
        $longitude = $coords ? (float) $coords[0] : null;

        return new self(
            id: $record->scan_id,
            ocr: $record->scans_ocr,
            us_state: $record->scans_us_state ?? null,
            guid: $record->scans_guid,
            plateImage: scan_img($record->scans_guid, VehicleImageTypeEnum::Plate->value),
            vehicleImage: scan_img($record->scans_guid, VehicleImageTypeEnum::Car->value),
            address: $scanMetadata?->{ScanMetaType::FormattedAddress->value} ?? $scanMetadata?->address ?? $record->address ?? null,
            latitude: $latitude ?? ($record->lat ? (float) $record->lat : null),
            longitude: $longitude ?? ($record->lng ? (float) $record->lng : null),
            disposition: $scanMetadata?->disposition ?? $record->disposition ?? null,
            scanned_at: $record->scans_scanned_at instanceof Carbon ? $record->scans_scanned_at : Carbon::parse($record->scans_scanned_at),
            kit_org_id: $record->kit_org_id ?? null,
            camera_name: $record->scans_camera_name ?? null,
            kit_name: $record->kit_name ?? null,
        );
    }

    public static function fromRecord(Model $record): self
    {
        if ($record instanceof ClickhouseScan) {
            return self::fromClickhouseScan($record);
        }

        return self::fromScan($record);
    }
}
