<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Hit;
use App\Services\Aws\Sqs;
use Illuminate\Support\Facades\Log;

class ClearPlan
{
    protected Sqs $sqs;

    public function __construct()
    {
        $this->sqs = new Sqs(
            queueUrl: config('services.clearplan.queue_url'),
        );
    }

    public function sendHit(Hit $hit): bool
    {
        try {
            $hit->loadMissing(['hotlist.org', 'scan.scanMetaData', 'kit.org']);

            $payload = $this->buildPayload($hit);

            $identifier = $hit->id ?? 'flex-'.$hit->scan_id.'-'.$hit->hotlist_id;

            $this->sqs->sendClearplanMessage(
                body: json_encode($payload),
                messageAttributes: [
                    'HitType' => [
                        'Type' => 'String',
                        'Value' => $hit->hit_type,
                    ],
                ],
                messageGroupId: (string) $identifier,
                messageDeduplicationId: $identifier.'-'.now()->timestamp,
            );

            return true;
        } catch (\Exception $exception) {
            Log::error('ClearPlan SQS error', [
                'hit_id' => $hit->id,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    protected function buildPayload(Hit $hit): array
    {
        return \array_merge(
            ['CAME_FROM_CLARITY_REQUEUE' => true],
            HitPayload::build($hit),
        );
    }
}
