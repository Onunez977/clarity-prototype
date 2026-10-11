<?php

namespace App\Services;

use Spatie\Activitylog\Contracts\Activity as ActivityContract;

class ActivityLogger extends \Spatie\Activitylog\ActivityLogger
{
    public function log(string $description): ?ActivityContract
    {
        if (session()->has('impersonator')) {
            $this->withProperty('impersonator', session('impersonator'));
        }

        $this->withProperty('session', [
            'ip' => request()->hasHeader('x-forwarded-for')
                ? request()->header('x-forwarded-for')
                : request()->ip(),
            'user_agent' => request()->header('User-Agent'),
            'referer' => request()->header('referer'),
        ]);

        return parent::log($description);
    }
}
