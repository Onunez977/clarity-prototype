<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Filament\Actions\ZoneKmlImportAction;
use App\Models\User;
use App\Notifications\ZoneImportCompleted;
use App\Services\Zones\ZoneKmlImporter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Large-file KML zone import (see {@see ZoneKmlImportAction}
 * for the size threshold that routes here instead of the synchronous path).
 * Reads the persisted upload, runs it through the shared {@see ZoneKmlImporter},
 * emails the acting user the result, and always deletes the persisted file.
 */
class ImportZonesFromKml implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Never retry — a stored file that's missing or unreadable once will
     * still be missing on a retry, and a retry after the file has already
     * been deleted (see the `finally` below) would just repeat the failure.
     */
    public int $tries = 1;

    public function __construct(
        public string $storedPath,
        public string $disk,
        public int $orgId,
        public int $userId,
    ) {}

    public function handle(ZoneKmlImporter $importer): void
    {
        try {
            $disk = Storage::disk($this->disk);

            if (! $disk->exists($this->storedPath)) {
                throw new RuntimeException("ImportZonesFromKml: stored file missing at [{$this->storedPath}] on disk [{$this->disk}].");
            }

            $kml = $disk->get($this->storedPath);

            if ($kml === null) {
                throw new RuntimeException("ImportZonesFromKml: stored file unreadable at [{$this->storedPath}] on disk [{$this->disk}].");
            }

            $result = $importer->run($kml, $this->orgId, $this->userId);

            $this->notifyUser(new ZoneImportCompleted($result['created'], $result['skipped']));
        } finally {
            Storage::disk($this->disk)->delete($this->storedPath);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('ImportZonesFromKml: import failed', [
            'org_id' => $this->orgId,
            'user_id' => $this->userId,
            'exception' => $exception?->getMessage(),
        ]);

        $this->notifyUser(ZoneImportCompleted::failed());

        Storage::disk($this->disk)->delete($this->storedPath);
    }

    private function notifyUser(ZoneImportCompleted $notification): void
    {
        User::find($this->userId)?->notify($notification);
    }
}
