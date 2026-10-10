<?php

namespace InsightLPR\BlackAdam;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InsightLPR\BlackAdam\Enum\VehicleImageTypeEnum;
use InsightLPR\BlackAdam\Support\EloquentBuilderHelper;

class BlackAdam
{
    /**
     * @throws ValidationException
     */
    public static function generateSignedUrl(
        string $url, array $parameters = [],
        ?Carbon $expires = null): string
    {
        if (array_key_exists('signature', $parameters)) {
            throw ValidationException::withMessages([
                '"Signature" is a reserved parameter when generating signed urls. Please rename your parameter.'
            ]);
        }

        if (array_key_exists('expires', $parameters)) {
            throw ValidationException::withMessages([
                '"Expires" is a reserved parameter when generating signed urls. Please rename your parameter.'
            ]);
        }

        if ($expires) {
            $expires = $expires->getTimestamp();
            $parameters['expires'] = $expires;
        }
        ksort($parameters);

        $parameter_string = http_build_query($parameters);

        $sig = hash_hmac('sha256', $parameter_string, config('black-adam.INSIGHT_LPR_KEY'));

        return $url . '?' . $parameter_string . '&signature=' . $sig;
    }

    public static function validateSignedUrl(\Illuminate\Http\Request|string $request): bool
    {
        if (is_string($request)) {
            $request = \Illuminate\Http\Request::create($request);
        }

        if (!$request->has('signature')) {
            abort(401, 'Missing signature');
        }
        if ($request->has('expires') && $request->get('expires') < Carbon::now()->utc()->getTimestamp()) {
            abort(401, 'Expired');
        }
        $parameters = $request->all();

        $signature = $parameters['signature'];
        Arr::forget($parameters, ['signature']);

        ksort($parameters);

        $parameter_string = http_build_query($parameters);

        return hash_equals($signature, hash_hmac('sha256', $parameter_string, config('black-adam.INSIGHT_LPR_KEY')));
    }
}
