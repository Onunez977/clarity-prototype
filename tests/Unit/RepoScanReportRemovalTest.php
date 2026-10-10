<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\TestCase;

class RepoScanReportRemovalTest extends TestCase
{
    public function test_post_public_migrate_sql_no_longer_creates_repo_scan_report(): void
    {
        $statements = config('zod-migrations.post_public_migrate_sql', []);

        $this->assertIsArray($statements);

        foreach ($statements as $statement) {
            $this->assertStringNotContainsString('repo_scan_report', $statement);
        }
    }

    public function test_dead_repo_scan_report_model_is_removed(): void
    {
        $this->assertFalse(class_exists('App\\Models\\Views\\RepoScanReport'));
    }
}
