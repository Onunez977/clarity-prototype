<?php

declare(strict_types=1);

namespace App\Actions\Activity;

use App\Actions\Activity\Log\DefaultLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ActivityFactory
{
    public static function make(): self
    {
        return new self;
    }

    public function log(string $logName, array $data, ?Model $causer = null, ?Model $subject = null, array $session = []): void
    {
        $tracker = self::getTracker($logName);

        if ($impersonator = $session['impersonator'] ?? $tracker->impersonator()) {
            $data['_impersonator'] = $impersonator;
        }

        $data['_request']['clientIp'] = $session['clientIp'] ?? request()->getClientIp();
        $data['_request']['userAgent'] = $session['userAgent'] ?? request()->userAgent();

        /**
         * Sets the args for the tracker
         */
        $tracker->data(...$data);

        $activity = activity($tracker::$log_name)
            ->withProperties($tracker->properties($causer) ?? []);

        if ($causer) {
            $activity->causedBy($causer);
        }

        if ($subject) {
            $activity->performedOn($subject);
        }

        $activity->log($tracker->description($logName));
    }

    public static function getTracker(string $logName)
    {
        $class = '\\App\\Actions\\Activity\\Log\\'.Str::studly($logName).'Search';
        if (class_exists($class)) {
            return new $class;
        }

        return new DefaultLog;
    }
}
