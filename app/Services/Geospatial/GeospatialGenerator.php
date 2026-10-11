<?php

namespace App\Services\Geospatial;

use App\Models\Kit;
use App\Models\Org;
use App\Services\Geospatial\Concerns\GeneratesPathData;
use Illuminate\Support\Carbon;

/**
 * @codeCoverageIgnore
 */
class GeospatialGenerator
{
    use GeneratesPathData;

    protected Org|Kit|null $model = null;

    protected ?Carbon $start = null;

    protected ?Carbon $end = null;

    protected ?string $format = 'Path';

    protected ?string $layer = 'yesterday';

    protected array $kits = [];

    protected null|int|string $location = null;

    public function getData(bool $decode = false): string|array|null
    {
        $data = $this->format === 'GeoJson'
            ? self::getGeoJsonData()
            : self::getPathLayerData();

        return $decode
            ? \json_decode($data, true)
            : $data;
    }

    public function forLocation(int|string|null $location = null): self
    {
        $this->location = $location;

        return $this;
    }

    public function forModel(Org|Kit $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function forLayer(string $layer): self
    {
        $this->layer = $layer;

        return $this;
    }

    public function format(string $format): self
    {
        $this->format = $format;

        return $this;
    }

    public function start(Carbon $start): self
    {
        $this->start = $start;

        return $this;
    }

    public function end(Carbon $end): self
    {
        $this->end = $end;

        return $this;
    }

    public function forKits(array $kits): self
    {
        $this->kits = $kits;

        return $this;
    }

    public function getKits(): array
    {
        if (! empty($this->kits)) {
            return $this->kits;
        }

        if ($this->model instanceof Org) {
            return $this->model->getOrgKits()->pluck('id')->toArray();
        }

        if ($this->model instanceof Kit) {
            return [$this->model->id];
        }

        return [];
    }
}
