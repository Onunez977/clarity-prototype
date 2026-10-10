<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PDF
{
    protected static function generate(string $view, string $name, array $data = [], string $timezone = 'UTC', array $printOptions = [])
    {
        $date = now()->setTimezone($timezone)->format(config('app.date_format'));

        $data['name'] = $name;

        $printOptions = [...[
            'StoreFile' => 'true',
            'ConversionDelay' => 5,
            'Footer' => "<style>.right{float: right;}.left{float: left;}</style><div>{$name}</div><span class='right'>{$date}</span>",
        ], ...$printOptions];

        return Http::asMultipart()
            ->attach('File', view($view)->with($data)->render(), Str::uuid().'.html')
            ->post(
                'https://v2.convertapi.com/convert/html/to/pdf?Secret='.config('services.convertapi.secret'),
                $printOptions
            );
    }

    /**
     * @throws \Throwable
     * @throws ConnectionException
     */
    public static function stream(string $view, string $name, array $data = [], string $timezone = 'UTC', array $printOptions = [])
    {
        $response = static::generate($view, $name, $data, $timezone, $printOptions);

        if ($response->successful()) {
            $json = $response->json();

            return $json['Files'][0]['Url'];
        } else {
            Log::error('PDF Generation Error', [
                'pdf_response' => $response->body(),
            ]);

            throw new \Exception('Error generating PDF: '.$response->body());
        }
    }
}
