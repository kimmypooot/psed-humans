<?php

namespace Tests\Feature;

use App\Models\Agency;
use App\Models\FieldOffice;
use App\Models\HrMechanism;
use App\Support\AgencyMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgencyMappingTest extends TestCase
{
    use RefreshDatabase;

    private FieldOffice $ns;

    private FieldOffice $wl;

    protected function setUp(): void
    {
        parent::setUp();
        AgencyMapper::forgetIndex();
        $this->ns = FieldOffice::factory()->create(['code' => 'FO-NS', 'name' => 'Northern Samar']);
        $this->wl = FieldOffice::factory()->create(['code' => 'SO-WL', 'name' => 'West Leyte']);
    }

    private function official(string $name, FieldOffice $office): Agency
    {
        return Agency::factory()->create(['name' => $name, 'field_office_id' => $office->id, 'listed' => true]);
    }

    public function test_workbook_short_forms_resolve_to_the_official_agency(): void
    {
        $allen = $this->official('Municipal Government of Allen, Northern Samar', $this->ns);
        $naval = $this->official('Naval Water District', $this->ns);
        $baybay = $this->official('Baybay City Water District', $this->wl);
        $deped = $this->official('Department Of Education - Schools Division Office of Northern Samar', $this->ns);
        $sto = $this->official('Municipal Government of Sto. Niño, Samar', $this->ns);
        $this->official('Provincial Government of Northern Samar', $this->ns);

        $this->assertTrue($allen->is(AgencyMapper::resolve('ALLEN', $this->ns->id)));
        $this->assertTrue($allen->is(AgencyMapper::resolve('MGO ALLEN, NORTHERN SAMAR', $this->ns->id)));
        $this->assertTrue($naval->is(AgencyMapper::resolve('NAVAL W.D.', $this->ns->id)));
        $this->assertTrue($baybay->is(AgencyMapper::resolve('BAYBAY WATER DISTRICT', $this->wl->id)));
        $this->assertTrue($deped->is(AgencyMapper::resolve('DEPED - NORTHERN SAMAR', $this->ns->id)));
        $this->assertTrue($deped->is(AgencyMapper::resolve('DEPED NORTHERN SAMAR', $this->ns->id)));
        $this->assertTrue($sto->is(AgencyMapper::resolve('STO. NINO', $this->ns->id)));
        $this->assertSame('Provincial Government of Northern Samar', AgencyMapper::resolve('PGO - NORTHERN SAMAR', $this->ns->id)->name);
    }

    public function test_a_name_that_fits_two_agencies_or_none_is_not_guessed(): void
    {
        $this->official('Municipal Government of San Isidro, Northern Samar', $this->ns);
        $this->official('Municipal Government of San Isidro, Leyte', $this->wl);
        $this->official('Municipal Government of Biliran, Biliran', $this->ns);

        $this->assertNull(AgencyMapper::resolve('SAN ISIDRO', null));
        $this->assertSame('Northern Samar', AgencyMapper::resolve('SAN ISIDRO', $this->ns->id)->fieldOffice->name);
        $this->assertNull(AgencyMapper::resolve('BILIRAN', $this->ns->id));
        $this->assertNull(AgencyMapper::resolve('NSU', $this->ns->id));
    }

    public function test_the_command_moves_mechanisms_and_removes_signature_rows(): void
    {
        $allen = $this->official('Municipal Government of Allen, Northern Samar', $this->ns);
        $short = Agency::factory()->create(['name' => 'MGO ALLEN, NORTHERN SAMAR', 'field_office_id' => $this->ns->id, 'listed' => false]);
        $junk = Agency::factory()->create(['name' => 'Action Officer', 'field_office_id' => $this->ns->id, 'listed' => false]);
        $kept = Agency::factory()->create(['name' => 'NSU', 'field_office_id' => $this->ns->id, 'listed' => false]);
        $moved = HrMechanism::factory()->for($short)->create();
        HrMechanism::factory()->for($junk)->create();
        $stays = HrMechanism::factory()->for($kept)->create();

        $this->artisan('agencies:map-legacy')->assertSuccessful();

        $this->assertSame($allen->id, $moved->fresh()->agency_id);
        $this->assertNull(Agency::find($short->id));
        $this->assertNull(Agency::find($junk->id));
        $this->assertSame(0, HrMechanism::where('agency_id', $junk->id)->count());
        $this->assertSame($kept->id, $stays->fresh()->agency_id);
        $this->assertDatabaseHas('agency_aliases', ['agency_id' => $allen->id, 'name' => 'MGO ALLEN, NORTHERN SAMAR']);
    }
}
