<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ActionOfficer;
use App\Models\HrMechanism;
use App\Models\User;
use App\Support\ActionOfficers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ActionOfficerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_workbook_cell_is_read_into_one_or_two_sets_of_initials(): void
    {
        $this->assertSame(['RMA'], ActionOfficers::parse('RMA'));
        $this->assertSame(['GQC', 'RMA'], ActionOfficers::parse('GQC & RMA'));
        $this->assertSame(['NL', 'RMA'], ActionOfficers::parse('NL/RMA'));
        $this->assertSame(['BPG'], ActionOfficers::parse('c/o BPG'));
        $this->assertSame(['JNAM', 'KBMB'], ActionOfficers::parse('jnam & kbmb'));
        $this->assertSame([], ActionOfficers::parse(null));
    }

    public function test_a_mechanism_keeps_both_officers_in_the_order_written_and_known_ones_get_their_name(): void
    {
        $mechanism = HrMechanism::factory()->create();

        ActionOfficers::sync($mechanism, 'GQC & RMA');

        $officers = $mechanism->officers()->get();
        $this->assertSame(['GQC', 'RMA'], $officers->pluck('initials')->all());
        $this->assertSame('Rowell M. Amor', $officers->last()->name);
        $this->assertSame(6, $officers->last()->imis_user_id);
    }

    public function test_an_officer_is_linked_to_the_account_when_that_imis_person_signs_in(): void
    {
        $officer = ActionOfficer::forInitials('RMA');
        $user = User::factory()->role(UserRole::PsedStaff)->create(['imis_user_id' => 6]);

        ActionOfficer::linkAccounts($user);

        $this->assertSame($user->id, $officer->fresh()->user_id);
    }

    public function test_psed_staff_can_name_and_later_correct_an_officer(): void
    {
        $officer = ActionOfficer::forInitials('MN');
        $staff = User::factory()->role(UserRole::PsedStaff)->create();

        $this->actingAs($staff)->patchJson("/action-officers/{$officer->id}", ['name' => 'Maria  N. Navarro'])
            ->assertOk()->assertJson(['name' => 'Maria N. Navarro']);

        $this->actingAs($staff)->patchJson("/action-officers/{$officer->id}", ['name' => 'Marian N. Navarro'])->assertOk();
        $this->assertSame('Marian N. Navarro', $officer->fresh()->name);
    }

    private function fakeImis(): void
    {
        config(['services.imis.api_url' => 'http://imis.test', 'services.imis.api_token' => 'secret']);
        Cache::forget('imis.directory');
        Http::fake(['imis.test/*' => Http::response(['data' => [
            ['id' => 40, 'first_name' => 'Zed', 'middle_name' => 'Yu', 'last_name' => 'Zamora', 'name_extension' => null, 'position' => 'Clerk'],
            ['id' => 6, 'first_name' => 'Rowell', 'middle_name' => 'Marinay', 'last_name' => 'Amor', 'name_extension' => null, 'position' => 'SHRS'],
            ['id' => 21, 'first_name' => 'Don Mark Phillipe', 'middle_name' => 'Delmo', 'last_name' => 'De Los Reyes', 'name_extension' => 'Jr.', 'position' => null],
        ]])]);
    }

    public function test_the_directory_lists_employees_by_last_name_first_name_middle_initial(): void
    {
        $this->fakeImis();
        $staff = User::factory()->role(UserRole::PsedStaff)->create();

        $this->actingAs($staff)->getJson('/action-officers/directory')->assertOk()
            ->assertJsonPath('employees.*.label', ['Amor, Rowell M.', 'De Los Reyes, Don Mark Phillipe D. Jr.', 'Zamora, Zed Y.']);

        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer secret'));
    }

    public function test_picking_an_employee_takes_the_name_from_imis_and_links_the_account(): void
    {
        $this->fakeImis();
        $officer = ActionOfficer::create(['initials' => 'XYZ']);
        $staff = User::factory()->role(UserRole::PsedStaff)->create(['imis_user_id' => 40]);

        $this->actingAs($staff)->patchJson("/action-officers/{$officer->id}", ['imis_user_id' => 40, 'name' => 'Forged Name'])->assertOk();

        $officer->refresh();
        $this->assertSame('Zed Y. Zamora', $officer->name);
        $this->assertSame(40, $officer->imis_user_id);
        $this->assertSame($staff->id, $officer->user_id);

        $this->actingAs($staff)->patchJson("/action-officers/{$officer->id}", ['imis_user_id' => 999])->assertStatus(422);
    }

    public function test_without_imis_the_directory_is_empty_and_a_name_can_still_be_typed(): void
    {
        config(['services.imis.api_url' => null, 'services.imis.api_token' => null]);
        Cache::forget('imis.directory');
        $staff = User::factory()->role(UserRole::PsedStaff)->create();

        $this->actingAs($staff)->getJson('/action-officers/directory')->assertOk()->assertJson(['employees' => []]);
    }

    public function test_a_mistyped_set_of_initials_is_merged_into_the_right_one(): void
    {
        $both = HrMechanism::factory()->create();
        $only = HrMechanism::factory()->create();
        $mistyped = ActionOfficer::create(['initials' => 'GCQ']);
        $both->officers()->attach([$mistyped->id => ['position' => 1], ActionOfficer::forInitials('GQC')->id => ['position' => 2]]);
        $only->officers()->attach([$mistyped->id => ['position' => 1]]);

        $this->assertSame(2, ActionOfficer::mergeAliases());

        $this->assertNull(ActionOfficer::where('initials', 'GCQ')->first());
        $this->assertSame(['GQC'], $both->officers()->pluck('initials')->all());
        $this->assertSame(['GQC'], $only->officers()->pluck('initials')->all());
        $this->assertSame(['GQC'], ActionOfficers::parse('GQC') === ['GQC'] ? [ActionOfficer::forInitials('GCQ')->initials] : []);
    }

    public function test_only_psed_can_name_officers(): void
    {
        $officer = ActionOfficer::forInitials('MN');
        $director = User::factory()->role(UserRole::Director)->create();

        $this->actingAs($director)->patchJson("/action-officers/{$officer->id}", ['name' => 'Maria N. Navarro'])->assertForbidden();
        $this->assertNull($officer->fresh()->name);
    }
}
