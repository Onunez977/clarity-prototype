<?php

namespace App\Log;

use Illuminate\Log\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * @codeCoverageIgnore
 */
class DataDogHandler
{
    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getHandlers() as $handler) {
            $handler->setFormatter(new JsonFormatter);
        }

        $logger->getLogger()->pushProcessor(function (LogRecord $record) {

            $record['extra'] = [
                'log_severity' => match ($record->level) {
                    Level::Debug => 7,
                    Level::Info => 6,
                    Level::Notice => 5,
                    Level::Warning => 4,
                    Level::Error => 3,
                    Level::Critical => 2,
                    Level::Alert => 1,
                    Level::Emergency => 0
                },
            ];

            if (auth()->check()) {
                $record['extra']['user']['id'] = auth()->user()->id;
                $record['extra']['user']['name'] = auth()->user()->name;
                $record['extra']['user']['email'] = auth()->user()->email;

                if ($record->level->isHigherThan(Level::Info)) {
                    $record['extra']['user']['org'] = [
                        'id' => auth()->user()->org_id,
                        'name' => auth()->user()->org->name,
                    ];
                }

            }

            return $record;
        });
    }
}
