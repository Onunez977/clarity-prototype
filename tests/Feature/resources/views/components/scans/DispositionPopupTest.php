<?php

namespace Tests\Feature\resources\views\components\scans;

use App\Enums\DispositionEnum;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class DispositionPopupTest extends TestCase
{
    public function test_change_disposition_wires_row_removal_for_missed_opportunity(): void
    {
        $rendered = Blade::render('<x-scans.disposition-popup />');

        // The 4th argument to updateScanDisposition must be the rowKey, gated to
        // missed-opportunity mode — so any disposition change (Valid/Reviewed/Invalid)
        // removes the row from the awaiting-only Missed Opportunities table. Scans/Hits
        // (missedOpportunity=false) pass null and are unaffected.
        $this->assertMatchesRegularExpression(
            "/this\\.missedOpportunity\\s*\\?\\s*'scan-' \\+ \\(this\\.rowLoop\\?\\.index \\?\\? this\\.modelId\\)\\s*:\\s*null/",
            $rendered
        );

        // The 3rd argument (reason) must remain 'other' — never overloaded with the rowKey.
        $this->assertMatchesRegularExpression(
            "/updateScanDisposition\\(\\s*this\\.modelId,\\s*value,\\s*'other',/",
            $rendered
        );
    }

    public function test_invalid_scan_reason_button_branch_is_untouched(): void
    {
        $rendered = Blade::render('<x-scans.disposition-popup />');

        $invalidScan = DispositionEnum::INVALID_SCAN->value;

        // The separate reason-button branch already passes rowLoop.index directly and
        // must remain unaffected by this fix.
        $this->assertStringContainsString(
            "window.updateScanDisposition(modelId, '{$invalidScan}',",
            $rendered
        );
        $this->assertStringContainsString(
            "'scan-'+rowLoop.index",
            $rendered
        );
    }
}
