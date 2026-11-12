<?php

namespace Tests\Feature;

use App\Enums\MechanismStatus;
use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\HrMechanism;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SignsPdfs;
use Tests\TestCase;

/** Taking back the most recent step, while nothing has left PSED. */
class UndoTest extends TestCase
{
    use RefreshDatabase;
    use SignsPdfs;

    private User $staff;

    private User $officer;

    private User $chief;

    private HrMechanism $mechanism;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        config(['services.imis.api_url' => 'http://imis.test', 'services.imis.api_token' => 'secret']);
        Cache::forget('imis.directory');
        Cache::forget('imis.directory.last_good');
        Http::fake(['imis.test/*' => Http::response(['data' => [
            ['id' => 66, 'honorific' => null, 'first_name' => 'Gina', 'middle_name' => 'Abellana', 'last_name' => 'Crucio', 'name_extension' => null, 'position' => 'Director III'],
        ]])]);

        $this->staff = User::factory()->role(UserRole::PsedStaff)->create();
        $this->officer = User::factory()->role(UserRole::PsedStaff)->create();
        $this->chief = User::factory()->role(UserRole::PsedChief)->create(['name' => 'Banello Gabon']);
        $this->mechanism = HrMechanism::factory()->for(Agency::factory()->create(['listed' => true]))->create(['status' => MechanismStatus::Received, 'contact_email' => 'hr@agency.gov.ph']);
    }

    private function send(User $by, string $action, array $data = [])
    {
        return $this->actingAs($by)->post("/mechanisms/{$this->mechanism->id}/{$action}", $data);
    }

    private function undo(User $by, string $reason = 'Wrong officer.')
    {
        return $this->send($by, 'undo', ['remarks' => $reason]);
    }

    private function current(): MechanismStatus
    {
        return $this->mechanism->fresh()->status;
    }

    private function can(User $by): array
    {
        return $this->actingAs($by)->getJson("/mechanisms/{$this->mechanism->id}")->json();
    }

    private function route(): void
    {
        $this->send($this->staff, 'route', ['officer_id' => $this->officer->id])->assertRedirect();
    }

    private function forward(): void
    {
        $this->send($this->officer, 'forward', ['reviewed' => UploadedFile::fake()->create('reviewed.docx', 10), 'checklist' => UploadedFile::fake()->create('staff-checklist.pdf', 10)])->assertRedirect();
    }

    private function approve(): void
    {
        $this->send($this->chief, 'chief-approve', ['signed_checklist' => $this->signedPdf('BANELLO PABUA GABON', name: 'checklist (signed).pdf')])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_routing_can_be_undone_by_psed_with_a_reason_and_clears_the_clock(): void
    {
        $this->route();
        $this->assertNotNull($this->mechanism->fresh()->due_at);

        $this->assertSame('Undo routing', $this->can($this->staff)['undo_label']);
        $this->assertTrue($this->can($this->staff)['can']['undo']);
        $this->assertFalse($this->can(User::factory()->role(UserRole::Director)->create())['can']['undo']);

        $this->undo(User::factory()->role(UserRole::Director)->create())->assertForbidden();
        $this->undo($this->staff, '')->assertSessionHasErrors('remarks');
        $this->assertSame(MechanismStatus::Routed, $this->current());

        $this->undo($this->staff)->assertRedirect()->assertSessionHasNoErrors();

        $m = $this->mechanism->fresh();
        $this->assertSame(MechanismStatus::Received, $m->status);
        $this->assertNull($m->action_officer_id);
        $this->assertNull($m->due_at);
        $this->assertNull($m->routed_at);
        $this->assertSame(0, $m->officers()->count());
        $this->assertDatabaseHas('hr_mechanism_events', ['hr_mechanism_id' => $m->id, 'action' => 'undone', 'user_id' => $this->staff->id, 'remarks' => 'Undo routing. Wrong officer.']);
        $this->assertDatabaseHas('hr_mechanism_events', ['hr_mechanism_id' => $m->id, 'action' => 'routed']);

        // Nothing left to undo, and it can be routed again.
        $this->undo($this->staff)->assertForbidden();
        $this->route();
        $this->assertSame(MechanismStatus::Routed, $this->current());
    }

    public function test_routing_again_from_routed_is_not_undone(): void
    {
        $this->route();
        $other = User::factory()->role(UserRole::PsedStaff)->create();
        $this->send($this->staff, 'route', ['officer_id' => $other->id])->assertRedirect();

        $this->assertFalse($this->can($this->staff)['can']['undo'], 'route it to the right person instead');
    }

    public function test_the_acknowledgement_is_undone_only_by_its_officer_and_the_steps_before_it_in_turn(): void
    {
        $this->route();
        $this->send($this->officer, 'acknowledge')->assertRedirect();
        $due = $this->mechanism->fresh()->due_at;

        $this->undo($this->staff)->assertForbidden();
        $this->undo($this->officer)->assertRedirect();
        $this->assertSame(MechanismStatus::Routed, $this->current());
        $this->assertNull($this->mechanism->fresh()->acknowledged_at);
        $this->assertEquals($due, $this->mechanism->fresh()->due_at, 'the clock keeps running from routing');

        // The undone acknowledgement is matched with its undo, so the routing before it can be undone next.
        $this->assertSame('Undo routing', $this->can($this->staff)['undo_label']);
        $this->undo($this->staff)->assertRedirect();
        $this->assertSame(MechanismStatus::Received, $this->current());
    }

    public function test_forwarding_can_be_withdrawn_until_the_chief_decides(): void
    {
        $this->route();
        $this->send($this->officer, 'acknowledge');
        $this->forward();
        $this->assertSame(MechanismStatus::ReviewedByChief, $this->current());

        $this->undo($this->chief)->assertForbidden();
        $this->undo($this->staff)->assertForbidden();
        $this->assertSame('Withdraw from the Chief', $this->can($this->officer)['undo_label']);

        $this->undo($this->officer, 'Missing the resolution.')->assertRedirect();

        $this->assertSame(MechanismStatus::Processing, $this->current());
        $this->assertNull($this->mechanism->fresh()->date_evaluated);
        $this->assertTrue($this->mechanism->fresh()->hasReviewDocuments(), 'the attached documents stay');
        Notification::assertSentTo($this->chief, \App\Notifications\MechanismActivity::class, fn ($n) => ($n->toArray($this->chief)['kind'] ?? null) === 'undone');

        // And it can be forwarded again.
        $this->send($this->officer, 'forward')->assertRedirect();
        $this->assertSame(MechanismStatus::ReviewedByChief, $this->current());
    }

    public function test_the_chief_can_undo_a_decision_and_the_officers_checklist_is_current_again(): void
    {
        $this->route();
        $this->send($this->officer, 'acknowledge');
        $this->forward();
        $this->approve();
        $this->assertSame(MechanismStatus::Reviewed, $this->current());
        $this->assertSame('checklist (signed).pdf', $this->mechanism->fresh()->currentAttachments()->firstWhere('kind', 'checklist')->name);

        $this->undo($this->officer)->assertForbidden();
        $this->assertSame('Undo approval', $this->can($this->chief)['undo_label']);
        $this->undo($this->chief, '')->assertSessionHasErrors('remarks');

        $this->undo($this->chief, 'I approved the wrong one.')->assertRedirect()->assertSessionHasNoErrors();

        $m = $this->mechanism->fresh();
        $this->assertSame(MechanismStatus::ReviewedByChief, $m->status);
        $this->assertNull($m->date_reviewed);
        $this->assertSame('staff-checklist.pdf', $m->currentAttachments()->firstWhere('kind', 'checklist')->name, 'the officer\'s checklist is current again');
        $this->assertSame(['staff-checklist.pdf', 'checklist (signed).pdf'], $m->attachments()->where('kind', 'checklist')->pluck('name')->all(), 'the signed one is kept');
        $this->assertNotNull($m->attachments()->where('name', 'checklist (signed).pdf')->first()->reverted_at);
        Notification::assertSentTo($this->officer, \App\Notifications\MechanismActivity::class, fn ($n) => ($n->toArray($this->officer)['kind'] ?? null) === 'undone');

        // The Chief decides again, this time For Compliance, and undoes that too.
        $this->send($this->chief, 'compliance', ['remarks' => 'The resolution is missing.', 'signed_checklist' => $this->signedPdf('BANELLO PABUA GABON', name: 'second (signed).pdf')])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(MechanismStatus::ForCompliance, $this->current());
        $this->assertSame('Undo For Compliance', $this->can($this->chief)['undo_label']);
        $this->undo($this->chief)->assertRedirect();
        $this->assertSame(MechanismStatus::ReviewedByChief, $this->current());
        $this->assertNull($this->mechanism->fresh()->remarks);
        $this->assertSame('staff-checklist.pdf', $this->mechanism->fresh()->currentAttachments()->firstWhere('kind', 'checklist')->name);
    }

    public function test_nothing_can_be_undone_once_the_officer_built_on_the_decision_or_the_agency_was_emailed(): void
    {
        $this->route();
        $this->send($this->officer, 'acknowledge');
        $this->forward();
        $this->approve();

        $letter = ['transmittal' => [
            'date' => '2027-03-09', 'addressee_name' => 'HON. JUAN D. DELA CRUZ', 'addressee_position' => 'Municipal Mayor',
            'addressee_address' => "Municipal Government\nProvince of Leyte", 'salutation' => 'Mayor Dela Cruz', 'subject' => '2027 MSP', 'signatory_id' => 66,
        ]];
        $this->send($this->officer, 'generate-transmittal', $letter)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse($this->can($this->chief)['can']['undo'], 'the officer generated the transmittal');
        $this->undo($this->chief)->assertForbidden();

        $this->send($this->officer, 'transmit', ['signed_transmittal' => UploadedFile::fake()->create('signed.pdf', 10)])->assertRedirect();
        $this->assertSame(MechanismStatus::TransmittalApproved, $this->current());

        foreach ([$this->chief, $this->officer, $this->staff] as $person) {
            $this->assertFalse($this->can($person)['can']['undo'], 'the agency was emailed');
            $this->undo($person)->assertForbidden();
        }
    }

    public function test_the_agency_is_not_shown_the_undone_steps(): void
    {
        $this->route();
        $this->undo($this->staff)->assertRedirect();

        $actions = collect(\App\Support\MechanismTimeline::history($this->mechanism->fresh(), true))->pluck('action');
        $this->assertNotContains('undone', $actions->all());
        $this->assertContains('undone', collect(\App\Support\MechanismTimeline::history($this->mechanism->fresh(), false))->pluck('action')->all());
    }
}
