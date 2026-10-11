<?php

declare(strict_types=1);

namespace Tests\Filament\Exports;

use App\Clickhouse\Models\LprRepossessionExportRow;
use App\Filament\Exports\LprRepossessionExporter;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\Models\Export;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('exports')]
class LprRepossessionExporterTest extends TestCase
{
    public function test_column_contract_is_csv_on_s3_high_queue_with_a_blank_repo_agent_name(): void
    {
        $columns = LprRepossessionExporter::getColumns();

        $this->assertSame(
            ['vin', 'plate', 'case_number', 'claimed_by', 'repo_agent_name'],
            array_map(fn ($column): string => $column->getName(), $columns),
        );
        $this->assertSame(
            ['VIN', 'Plate', 'RDN Case', 'Claimed By', 'Repo Agent Name'],
            array_map(fn ($column): ?string => $column->getLabel(), $columns),
        );

        $export = new Export;
        $export->successful_rows = 2;
        $export->total_rows = 2;

        $exporter = new LprRepossessionExporter($export, [
            'vin' => 'VIN',
            'plate' => 'Plate',
            'case_number' => 'RDN Case',
            'claimed_by' => 'Claimed By',
            'repo_agent_name' => 'Repo Agent Name',
        ], []);

        $this->assertSame(LprRepossessionExportRow::class, LprRepossessionExporter::getModel());
        $this->assertSame([ExportFormat::Csv], $exporter->getFormats());
        $this->assertNotContains(ExportFormat::Xlsx, $exporter->getFormats());
        $this->assertSame('s3', $exporter->getFileDisk());
        $this->assertSame('{high}', $exporter->getJobQueue());
        $this->assertSame('lpr-repossession-export', $exporter->getJobBatchName());
        $this->assertStringContainsString('2 rows exported', $exporter::getCompletedNotificationBody($export));

        $row = new LprRepossessionExportRow;
        $row->forceFill([
            'id' => '1|2|VIN123',
            'vin' => 'VIN123',
            'plate' => 'PLATE',
            'case_number' => null,
            'claimed_by' => 'Agent Co',
            'repo_agent_name' => '',
        ]);

        $this->assertSame(['VIN123', 'PLATE', '', 'Agent Co', ''], $exporter($row));

        $source = file_get_contents(app_path('Filament/Exports/LprRepossessionExporter.php'));
        $this->assertIsString($source);
        $this->assertStringNotContainsString('maxRows', $source);
        $this->assertStringNotContainsString('cityHash64', $source);
        $this->assertStringNotContainsString('Xlsx', $source);
    }
}
