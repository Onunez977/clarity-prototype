<?php

namespace App\Jobs;

use App\Clickhouse\Models\Analytics;
use App\Dto\AnalyticsDataDto;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class TrackAnalytics implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public int $timeout = 120;

    public string $id;

    public string $createdAt;

    public function __construct(
        public string $feature,
        public ?int $userId,
        public ?int $orgId,
        public AnalyticsDataDto $data,
        public ?string $page = null,
    ) {
        $this->onQueue('{low}');

        // ClickHouse 26.2+ deduplicates byte-identical insert blocks, so every
        // event must carry its own id — leaving it to the column DEFAULT made
        // repeat events (same user reloading a page) silently vanish. Generated
        // at dispatch time so a queue retry re-inserts the same id and dedup
        // gives us exactly-once instead of data loss.
        $this->id = (string) Str::uuid();
        $this->createdAt = now()->format('Y-m-d H:i:s');
    }

    public function handle(): void
    {
        // Let failures propagate so the queue retries instead of silently
        // dropping the event (dedup on the dispatch-time id keeps it exactly-once).
        Analytics::insertAssoc([
            [
                'id' => $this->id,
                'feature' => $this->feature,
                'page' => $this->page ?? '',
                'user_id' => $this->userId,
                'org_id' => $this->orgId,
                'data' => $this->data->toJson(),
                'created_at' => $this->createdAt,
            ],
        ]);
    }
}
