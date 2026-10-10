<?php

declare(strict_types=1);

namespace App\Actions\Activity\Log;

use App\Models\Hotlist;
use App\Models\User;
use Illuminate\Support\Arr;

class DefaultLog
{
    public static string $log_name = 'default';

    protected array $casts = [];

    public function __construct(
        protected array $data = [],
        protected mixed $impersonator = null,
    ) {
        $this->checkIfImpersonated();
    }

    public function data(...$data): self
    {
        foreach ($this->casts as $field => $cast) {
            if (data_get($data, $field)) {
                $data[$field] = $cast::make()->set(data_get($data, $field));
            }
        }

        $this->data = $data;

        return $this;
    }

    public function description(string $logName): string
    {
        return ':causer.email searched for "'.$logName.'"';
    }

    protected function checkIfImpersonated(): void
    {
        $token = \str(request()->headers->get('authorization'))->after('Bearer ')->trim()->value();
        if ($token) {
            $this->impersonator = data_get(jwtDecode($token), 'payload.impersonate');
        }
    }

    public function impersonator(): ?array
    {
        return $this->impersonator;
    }

    public function properties(?User $causer = null): array
    {
        try {
            if ($causer && $plate_numbers = ($this->data['plateNumber'] ?? null)) {
                $valid_plates = Hotlist::withoutGlobalScopes()
                    ->when($causer, fn ($query) => $query->where('org_id', $causer->org_id))
                    ->whereIn('plate', $plate_numbers)
                    ->select('plate')
                    ->get()
                    ->pluck('plate')
                    ->toArray();

                $invalid = \array_diff($plate_numbers, $valid_plates);

                if ($invalid) {
                    $this->data['platesNotOnHotlist'] = Arr::flatten($invalid);
                }
            }
        } catch (\Exception $exception) {
            //
        }

        return $this->data;
    }

    public function __get($name)
    {
        $val = data_get($this->data, $name);
        if (in_array($name, array_keys($this->casts))) {
            $val = $this->casts[$name]::make()->get($val);
        }

        return $val;
    }
}
