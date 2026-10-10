<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\HitTypeEnum;
use App\Enums\HotlistStatusEnum;
use App\Models\Hit;
use App\Models\Hotlist;
use App\Models\Kit;
use App\Models\Scan;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\HasSuperUser;
use Tests\TestCase;

#[Group('controllers')]
#[Group('flex-match')]
class FlexMatchHotlistsControllerTest extends TestCase
{
    use HasSuperUser;

    public function test_returns_variant_matched_hotlists(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create();

        $hotlist = Hotlist::factory()
            ->forOrg($this->user->org)
            ->plate('8KXR892', 'CA')
            ->create(['status' => HotlistStatusEnum::Open]);

        // OCR misread: B instead of 8
        $scan = Scan::factory()
            ->belongsToKit($kit)
            ->create(['ocr' => 'BKXR892', 'us_state' => 'CA']);

        $response = $this->post(route('scan.flex-match-hotlists'), [
            'scan_id' => $scan->id,
        ]);

        $response->assertSuccessful();

        $hotlists = $response->json('hotlists');
        $this->assertCount(1, $hotlists);
        $this->assertEquals($hotlist->id, $hotlists[0]['id']);
        $this->assertEquals('8KXR892', $hotlists[0]['plate']);
    }

    public function test_excludes_exact_match_hotlists(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create();

        Hotlist::factory()
            ->forOrg($this->user->org)
            ->plate('ABC1234', 'CA')
            ->create(['status' => HotlistStatusEnum::Open]);

        // Scan OCR matches exactly - not a flex match
        $scan = Scan::factory()
            ->belongsToKit($kit)
            ->create(['ocr' => 'ABC1234', 'us_state' => 'CA']);

        $response = $this->post(route('scan.flex-match-hotlists'), [
            'scan_id' => $scan->id,
        ]);

        $response->assertSuccessful();
        $this->assertCount(0, $response->json('hotlists'));
    }

    public function test_excludes_hotlists_from_other_orgs(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create();

        // Hotlist belongs to a different org
        Hotlist::factory()
            ->plate('8KXR892', 'CA')
            ->create(['status' => HotlistStatusEnum::Open]);

        $scan = Scan::factory()
            ->belongsToKit($kit)
            ->create(['ocr' => 'BKXR892', 'us_state' => 'CA']);

        $response = $this->post(route('scan.flex-match-hotlists'), [
            'scan_id' => $scan->id,
        ]);

        $response->assertSuccessful();
        $this->assertCount(0, $response->json('hotlists'));
    }

    public function test_excludes_non_open_hotlists(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create();

        Hotlist::factory()
            ->forOrg($this->user->org)
            ->plate('8KXR892', 'CA')
            ->create(['status' => HotlistStatusEnum::Closed]);

        $scan = Scan::factory()
            ->belongsToKit($kit)
            ->create(['ocr' => 'BKXR892', 'us_state' => 'CA']);

        $response = $this->post(route('scan.flex-match-hotlists'), [
            'scan_id' => $scan->id,
        ]);

        $response->assertSuccessful();
        $this->assertCount(0, $response->json('hotlists'));
    }

    public function test_excludes_hotlists_with_existing_hits(): void
    {
        $kit = Kit::factory()->forOrg($this->user->org)->create();

        $hotlist = Hotlist::factory()
            ->forOrg($this->user->org)
            ->plate('8KXR892', 'CA')
            ->create(['status' => HotlistStatusEnum::Open]);

        $scan = Scan::factory()
            ->belongsToKit($kit)
            ->create(['ocr' => 'BKXR892', 'us_state' => 'CA']);

        // An existing hit already exists for this scan+hotlist
        Hit::withoutGlobalScope('only_basic')->create([
            'scan_id' => $scan->id,
            'hotlist_id' => $hotlist->id,
            'hit_type' => HitTypeEnum::Probable_Match->value,
            'kit_id' => $kit->id,
        ]);

        $response = $this->post(route('scan.flex-match-hotlists'), [
            'scan_id' => $scan->id,
        ]);

        $response->assertSuccessful();
        $this->assertCount(0, $response->json('hotlists'));
    }

    public function test_returns_404_for_scan_from_other_org(): void
    {
        // Scan belongs to a different org's kit
        $scan = Scan::factory()->create(['ocr' => 'BKXR892']);

        $response = $this->post(route('scan.flex-match-hotlists'), [
            'scan_id' => $scan->id,
        ]);

        $response->assertNotFound();
    }

    public function test_validates_scan_id_required(): void
    {
        $response = $this->post(route('scan.flex-match-hotlists'), []);

        $response->assertSessionHasErrors('scan_id');
    }
}
