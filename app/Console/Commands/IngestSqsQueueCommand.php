<?php

namespace App\Console\Commands;

use App\Enums\HitTypeEnum;
use App\Events\HitEvent;
use App\Models\Hit;
use App\Models\Scan;
use App\Services\Aws\Sqs;
use Clickbar\Magellan\Data\Geometries\Point;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as CommandAlias;

/**
 * @codeCoverageIgnore
 */
class IngestSqsQueueCommand extends Command
{
    protected $signature = 'app:ingest-sqs-queue';

    public function handle(): int
    {
        Log::shareContext([
            'command' => 'app:ingest-sqs-queue',
        ]);

        Log::channel('hits')->info('Starting');
        $this->info(now()->toDateTimeString().' Starting');

        while (true) {
            $this->process();
            // Check memory usage and exit if too high
            if (memory_get_usage() > 128 * 1024 * 1024) { // 125MB limit
                Log::channel('hits')->warning('Memory limit reached, exiting');
                break;
            }
        }

        return CommandAlias::SUCCESS;
    }

    public function process(): void
    {
        $d = (new Sqs)
            ->setMaxNumberOfMessages(10)
            ->setQueueUrl('https://sqs.us-east-2.amazonaws.com/336279217542/clarity_hits');

        if ($d->getMessages()) {
            foreach ($d->getMessages() as $queueMessage) {
                $message = \json_decode($queueMessage['Body'], true);

                $data = \json_decode($message['Message'], true);
                $data['hit_type'] = $message['MessageAttributes']['HitType']['Value'];

                Log::shareContext([
                    'message_id' => $message['MessageId'],
                    'body' => $data,
                ]);

                Log::channel('hits')->info('processing message');

                $this->info("hit: {$data['insight_hit_id']}, message {$message['MessageId']}");

                try {
                    $hit = new Hit;
                    $hit->forceFill([
                        'id' => $data['insight_hit_id'],
                        'hotlist_id' => $data['insight_hotlist_id'],
                        'kit_id' => $data['insight_kit_id'],
                        'integration_id' => $data['insight_integration_id'],
                        'scan_id' => data_get($data, 'scans.0.id'),
                        'hit_type' => $data['hit_type'] ?? HitTypeEnum::Live->value,
                        'created_at' => $data['now'],
                    ]);

                    $scans = [];
                    foreach ($data['scans'] as $index => $dataScan) {
                        $scan = new Scan;
                        $scan->forceFill([
                            'id' => $dataScan['id'],
                            'guid' => $dataScan['guid'],
                            'plate' => data_get($data, 'plate'),
                            'state' => data_get($data, 'us_state'),
                            'geo' => Point::makeGeodetic(
                                latitude: data_get($dataScan, 'location.lat', 0.0),
                                longitude: data_get($dataScan, 'location.lng', 0.0)
                            ),
                            'scanned_at' => $dataScan['scanDate'],
                        ]);

                        if ($index === 0) {
                            $hit->setRelation('scan', $scan);
                        }

                        $scans[] = $scan;
                    }

                    // New event for hits + queued listeners
                    event(new HitEvent($hit, $scans));

                    $this->deleteMessage($d, $message, $queueMessage['ReceiptHandle'], $data);
                } catch (Exception $e) {
                    Log::channel('hits')->error('Exception', ['message' => $e->getMessage()]);

                    $this->error(now()->toDateTimeString()."Exception {$message['MessageId']} {$e->getMessage()} | ".$data['insight_hit_id'] ?? 'no hit id');
                    $this->deleteMessage($d, $message, $queueMessage['ReceiptHandle'], $data);

                    continue;
                }
            }
        }
    }

    protected function deleteMessage($d, $message, $receiptHandle, array $data): void
    {
        if (config('app.env') === 'production') {
            $type = $data['hit_type'] ?? (HitTypeEnum::Live->value.' (Fallback)');

            $this->info(now()->toDateTimeString().' Delete message: '.$message['MessageId'].' | hit: '.$data['insight_hit_id'].' | type: '.$type);

            $d->deleteMessage($receiptHandle);
        }
    }
}
