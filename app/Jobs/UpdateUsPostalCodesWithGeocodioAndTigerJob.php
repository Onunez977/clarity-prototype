<?php

namespace App\Jobs;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * @codeCoverageIgnore
 */
class UpdateUsPostalCodesWithGeocodioAndTigerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct()
    {
        $this->onQueue('{low}');
    }

    public function handle(): void
    {
        $sub_query = DB::connection('zod')
            ->table('tiger.us_zipcodes')
            ->whereNull('state')
            ->select('zip_code')
            ->limit(10000);

        $records_toUpdate = $sub_query->count();
        dump($records_toUpdate.' records to update.');

        if ($records_toUpdate == 0) {
            return;
        }

        $query = DB::connection('zod')
            ->table('tiger.us_zipcodes')
            ->selectRaw('json_agg(zip_code) as json')
            ->fromSub($sub_query, 't');

        $zip_codes = collect(json_decode($query->first()->json))->mapWithKeys(fn ($zip) => [(string) $zip => (string) $zip])->toArray();

        $results = Arr::get($this->batchDataRequest($zip_codes)->json(), 'results');

        collect($results)->each(function ($item) {
            $zip_code = $item['query'];

            $state = Arr::get($item, 'response.results.0.address_components.state');

            if ($state) {
                DB::connection('zod')->table('tiger.us_zipcodes')
                    ->where('zip_code', $zip_code)
                    ->update([
                        'state' => $state,
                    ]);
                dump('[GEO] Updated '.$zip_code.' to '.$state);
            } else {
                $this->locateStateFromTiger($zip_code);
            }
        });

    }

    protected function batchDataRequest($payload): PromiseInterface|Response
    {
        return Http::asJson()
            ->withBody($payload, 'application/json')
            ->asJson()
            ->timeout(300)
            ->post('https://api.geocod.io/v1.7/geocode?&api_key='.config('geocodio.api_key'), $payload);
    }

    protected function locateStateFromTiger(string $zip_code): void
    {
        $state = DB::connection('zod')
            ->table('tiger.zcta5_all')
            ->select('tiger.state.stusps as state')
            ->join('tiger.state', 'tiger.zcta5_all.statefp', '=', 'tiger.state.statefp')
            ->where('zcta5ce', $zip_code)
            ->first()->state;

        if ($state) {
            DB::connection('zod')->table('tiger.us_zipcodes')
                ->where('zip_code', $zip_code)
                ->update([
                    'state' => $state,
                ]);
            dump('[Tiger] Updated '.$zip_code.' to '.$state);
        } else {
            dump('No state found for '.$zip_code);
        }
    }
}
