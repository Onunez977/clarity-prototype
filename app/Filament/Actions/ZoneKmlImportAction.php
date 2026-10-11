<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Jobs\ImportZonesFromKml;
use App\Models\Zone;
use App\Services\Zones\KmlZoneParser;
use App\Services\Zones\ZoneKmlImporter;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * "Import KML" toolbar action for the Zones list — uploads a .kml file and
 * creates one zone per polygon placemark (see {@see KmlZoneParser}
 * for holes/skip rules). Files at or below {@see self::QUEUE_THRESHOLD_BYTES}
 * import synchronously with an immediate summary notification; larger files
 * are persisted and handed to the queued {@see ImportZonesFromKml} job, which
 * emails the result instead.
 */
class ZoneKmlImportAction
{
    public const QUEUE_THRESHOLD_BYTES = 262144;

    public const MAX_UPLOAD_KILOBYTES = 10240;

    public static function make(): Action
    {
        return Action::make('importKml')
            ->label('Import KML')
            ->icon('heroicon-o-arrow-up-tray')
            ->modalHeading('Import zones from KML')
            ->modalDescription('Upload a .kml file — one zone is created per polygon placemark.')
            ->modalSubmitActionLabel('Import')
            ->schema([
                Forms\Components\FileUpload::make('file')
                    ->label('KML file')
                    ->storeFiles(false)
                    // No acceptedFileTypes(): a .kml reports an empty/unknown browser
                    // MIME type, so FilePond's client-side type match would reject every
                    // valid file. The .kml requirement is enforced server-side by the
                    // extension rule below (reliable) + the parser tolerates non-KML content.
                    ->rules(['extensions:kml'])
                    ->maxSize(self::MAX_UPLOAD_KILOBYTES)
                    ->required(),
            ])
            ->action(function (array $data): void {
                Gate::authorize('create', Zone::class);

                /** @var TemporaryUploadedFile $file */
                $file = $data['file'];

                $orgId = auth()->user()->org_id;
                $userId = auth()->user()->id;

                if ($file->getSize() <= self::QUEUE_THRESHOLD_BYTES) {
                    self::importSync($file, $orgId, $userId);

                    return;
                }

                self::importQueued($file, $orgId, $userId);
            });
    }

    private static function importSync(TemporaryUploadedFile $file, int $orgId, int $userId): void
    {
        $kml = $file->get();
        $file->delete();

        $result = app(ZoneKmlImporter::class)->run($kml, $orgId, $userId);

        Notification::make()
            ->title("Imported {$result['created']} zones, skipped {$result['skipped']}")
            ->success()
            ->send();
    }

    private static function importQueued(TemporaryUploadedFile $file, int $orgId, int $userId): void
    {
        $path = 'kml-imports/'.Str::ulid().'.kml';

        // Use the application's default filesystem disk — never a hardcoded
        // one — so the queue worker reads from the same disk the web tier
        // wrote to (a mismatch would silently import 0 and still notify success).
        $disk = config('filesystems.default');

        Storage::disk($disk)->put($path, $file->get());
        $file->delete();

        ImportZonesFromKml::dispatch($path, $disk, $orgId, $userId);

        Notification::make()
            ->title("Import started — you'll be emailed when it finishes")
            ->success()
            ->send();
    }
}
