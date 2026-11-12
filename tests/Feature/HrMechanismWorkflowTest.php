<?php

namespace Tests\Feature;

use App\Enums\MechanismStatus;
use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\FieldOffice;
use App\Models\HrMechanism;
use App\Models\User;
use App\Notifications\MechanismStatusChanged;
use App\Support\Turnaround;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\SignsPdfs;
use Tests\TestCase;

class HrMechanismWorkflowTest extends TestCase
{
    use RefreshDatabase;
    use SignsPdfs;

    private function person(UserRole $role, ?FieldOffice $office = null): User
    {
        return User::factory()->role($role)->create(['field_office_id' => $office?->id]);
    }

    private function listedAgency(): Agency
    {
        return Agency::factory()->create(['listed' => true]);
    }

    /** The two documents the Action Officer attaches. The transmittal is generated, not uploaded. @return array<string, UploadedFile> */
    private function documents(): array
    {
        return [
            'reviewed' => UploadedFile::fake()->create('reviewed.pdf', 20),
            'checklist' => UploadedFile::fake()->create('checklist.pdf', 20),
        ];
    }

    /** What PSED fills in for the transmittal, with IMIS answering as it does (a Director IV and a Director III). @return array{transmittal: array<string, mixed>} */
    private function letter(array $override = []): array
    {
        config(['services.imis.api_url' => 'http://imis.test', 'services.imis.api_token' => 'secret']);
        Cache::forget('imis.directory');
        Cache::forget('imis.directory.last_good');
        Http::fake(['imis.test/*' => Http::response(['data' => [
            ['id' => 50, 'honorific' => null, 'first_name' => 'Marilyn', 'middle_name' => 'Eclipse', 'last_name' => 'Taldo', 'name_extension' => null, 'position' => 'Director IV'],
            ['id' => 66, 'honorific' => null, 'first_name' => 'Gina', 'middle_name' => 'Abellana', 'last_name' => 'Crucio', 'name_extension' => null, 'position' => 'Director III'],
            ['id' => 12, 'honorific' => null, 'first_name' => 'Kim Benedick', 'middle_name' => 'Macawile', 'last_name' => 'Banoyo', 'name_extension' => null, 'position' => 'Human Resource Specialist I'],
        ]])]);

        return ['transmittal' => $override + [
            'date' => '2027-03-09',
            'addressee_name' => 'HON. JUAN D. DELA CRUZ',
            'addressee_position' => 'Municipal Mayor',
            'addressee_address' => "Municipal Government of Javier\nProvince of Leyte",
            'salutation' => 'Mayor Dela Cruz',
            'subject' => '2027 Merit Selection Plan (MSP)',
            'signatory_id' => 66,
        ]];
    }

    private function submitted(?Agency $agency = null): HrMechanism
    {
        return HrMechanism::factory()->for($agency ?? $this->listedAgency())->create(['status' => MechanismStatus::Received, 'contact_email' => 'hr@agency.gov.ph']);
    }

    public function test_an_agency_submits_without_an_account_and_is_sent_an_acknowledgment(): void
    {
        Storage::fake('local');
        Notification::fake();
        $agency = $this->listedAgency();

        $response = $this->post('/submit', [
            'agency_id' => $agency->id,
            'mechanism_type' => 'MSP',
            'contact_email' => 'HR@Agency.gov.ph',
            'document' => UploadedFile::fake()->create('msp.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ]);

        $mechanism = HrMechanism::firstOrFail();
        $response->assertRedirect(URL::signedRoute('track.show', $mechanism));
        $this->assertSame(MechanismStatus::Received, $mechanism->status);
        $this->assertNotNull($mechanism->date_submitted);
        $this->assertSame('hr@agency.gov.ph', $mechanism->contact_email);
        Storage::disk('local')->assertExists($mechanism->document_path);

        Notification::assertSentOnDemand(MechanismStatusChanged::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'hr@agency.gov.ph' && $n->headline === 'We received your HR mechanism');
    }

    public function test_the_public_page_only_accepts_listed_agencies_and_needs_an_email(): void
    {
        Storage::fake('local');
        $unlisted = Agency::factory()->create(['listed' => false]);
        $file = UploadedFile::fake()->create('msp.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $this->post('/submit', ['agency_id' => $unlisted->id, 'mechanism_type' => 'MSP', 'contact_email' => 'a@b.gov.ph', 'document' => $file])->assertSessionHasErrors('agency_id');
        $this->post('/submit', ['agency_id' => $this->listedAgency()->id, 'mechanism_type' => 'MSP', 'document' => $file])->assertSessionHasErrors('contact_email');
        $this->post('/submit', ['agency_id' => $this->listedAgency()->id, 'mechanism_type' => 'MSP', 'contact_email' => 'a@b.gov.ph', 'document' => $file, 'website' => 'spam.example'])->assertSessionHasErrors('website');

        $this->assertSame(0, HrMechanism::count());
    }

    public function test_the_public_pages_list_the_agencies_and_need_no_sign_in(): void
    {
        $office = FieldOffice::factory()->create();
        Agency::factory()->create(['listed' => true, 'name' => 'Listed Agency', 'field_office_id' => $office->id]);
        Agency::factory()->create(['listed' => false, 'name' => 'Hidden Agency']);

        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Public/Submit')
            ->where('agencies.0.options.0.label', 'Listed Agency')
            ->has('agencies', 1));
        $this->get('/track')->assertOk();
    }

    public function test_the_clock_starts_when_psed_routes_it_and_only_that_officer_acknowledges(): void
    {
        $mechanism = $this->submitted();
        $staff = $this->person(UserRole::PsedStaff);
        $officer = $this->person(UserRole::PsedStaff);

        $this->actingAs($this->person(UserRole::Director))->post("/mechanisms/{$mechanism->id}/route", ['officer_id' => $officer->id])->assertForbidden();

        $this->actingAs($staff)->post("/mechanisms/{$mechanism->id}/route", ['officer_id' => $officer->id])->assertRedirect();
        $mechanism->refresh();
        $this->assertSame(MechanismStatus::Routed, $mechanism->status);
        $this->assertSame($officer->id, $mechanism->action_officer_id);
        $this->assertNull($mechanism->acknowledged_at);
        $this->assertNotNull($mechanism->routed_at);
        $this->assertEquals(Turnaround::dueFrom($mechanism->routed_at)->toDateString(), $mechanism->due_at->toDateString(), 'the clock starts at routing');
        $due = $mechanism->due_at;

        $this->actingAs($staff)->post("/mechanisms/{$mechanism->id}/acknowledge")->assertForbidden();

        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/acknowledge")->assertRedirect();
        $mechanism->refresh();
        $this->assertSame(MechanismStatus::Processing, $mechanism->status);
        $this->assertNotNull($mechanism->acknowledged_at);
        $this->assertEquals($due, $mechanism->due_at, 'acknowledging does not restart the clock');
    }

    public function test_staff_may_route_a_submission_to_themselves(): void
    {
        $mechanism = $this->submitted();
        $staff = $this->person(UserRole::PsedStaff);

        $this->actingAs($staff)->post("/mechanisms/{$mechanism->id}/route", ['officer_id' => $staff->id])->assertRedirect();
        $this->actingAs($staff)->post("/mechanisms/{$mechanism->id}/acknowledge")->assertRedirect();

        $this->assertSame(MechanismStatus::Processing, $mechanism->fresh()->status);
    }

    public function test_the_turnaround_is_twenty_working_days(): void
    {
        $monday = CarbonImmutable::parse('2027-03-01');
        $this->assertTrue($monday->isMonday());

        $this->assertSame('2027-03-29', Turnaround::dueFrom($monday)->toDateString());
        $this->assertSame(20, Turnaround::daysLeft(Turnaround::dueFrom($monday), $monday));
        $this->assertSame(-1, Turnaround::daysLeft($monday, $monday->addDay()));
        $this->assertSame(0, Turnaround::daysLeft($monday, $monday));
    }

    public function test_forwarding_to_the_chief_needs_the_reviewed_mechanism_and_the_checklist_only(): void
    {
        Storage::fake('local');
        $officer = $this->person(UserRole::PsedStaff);
        $mechanism = $this->submitted();
        $mechanism->update(['status' => MechanismStatus::Processing, 'action_officer_id' => $officer->id]);

        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/forward", ['reviewed' => UploadedFile::fake()->create('r.pdf', 5)])
            ->assertSessionHasErrors('reviewed');
        $this->assertSame(MechanismStatus::Processing, $mechanism->fresh()->status);

        // No transmittal is uploaded: a file sent under that name is ignored, and the two documents are enough.
        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/forward", $this->documents() + ['transmittal' => UploadedFile::fake()->create('t.pdf', 5)])->assertRedirect();

        $mechanism->refresh();
        $this->assertSame(MechanismStatus::ReviewedByChief, $mechanism->status);
        $this->assertTrue($mechanism->hasReviewDocuments());
        $this->assertSame(['checklist', 'reviewed'], $mechanism->currentAttachments()->pluck('kind')->sort()->values()->all());
    }

    public function test_the_chief_approves_then_the_action_officer_generates_and_transmits_the_letter_that_emails_the_agency_the_three_documents(): void
    {
        Storage::fake('local');
        Notification::fake();
        $officer = $this->person(UserRole::PsedStaff);
        $mechanism = $this->submitted();
        $mechanism->update(['status' => MechanismStatus::Processing, 'action_officer_id' => $officer->id]);
        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/forward", $this->documents());

        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/chief-approve")->assertForbidden();

        $chief = $this->person(UserRole::PsedChief);
        $this->actingAs($chief)->post("/mechanisms/{$mechanism->id}/chief-approve")->assertSessionHasErrors(['signed_checklist']);
        $this->actingAs($chief)->post("/mechanisms/{$mechanism->id}/chief-approve", ['signed_checklist' => $this->signedPdf($chief->name)])->assertRedirect();

        // The Chief's approval only signs the checklist: it does not make the transmittal and does not email the agency.
        $this->assertSame(MechanismStatus::Reviewed, $mechanism->fresh()->status);
        $this->assertSame(['checklist', 'reviewed'], $mechanism->fresh()->currentAttachments()->pluck('kind')->sort()->values()->all());
        Notification::assertSentOnDemandTimes(MechanismStatusChanged::class, 0);

        // The transmittal is the Action Officer's: the Chief cannot make it, and it cannot be sent before it exists.
        $this->actingAs($chief)->post("/mechanisms/{$mechanism->id}/generate-transmittal", $this->letter())->assertForbidden();
        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/transmit", ['signed_transmittal' => UploadedFile::fake()->create('signed.pdf', 5)])->assertSessionHasErrors('reviewed');
        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/generate-transmittal", $this->letter())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['checklist', 'reviewed', 'transmittal'], $mechanism->fresh()->currentAttachments()->pluck('kind')->sort()->values()->all());
        Notification::assertSentOnDemandTimes(MechanismStatusChanged::class, 0);

        // Only the Action Officer it is with transmits it, with the transmittal the Regional Director signed.
        $this->actingAs($chief)->post("/mechanisms/{$mechanism->id}/transmit", ['signed_transmittal' => UploadedFile::fake()->create('signed.pdf', 5)])->assertForbidden();
        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/transmit")->assertSessionHasErrors('signed_transmittal');
        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/transmit", ['signed_transmittal' => UploadedFile::fake()->create('signed.pdf', 5)])->assertRedirect();

        $mechanism->refresh();
        $this->assertSame(MechanismStatus::TransmittalApproved, $mechanism->status);
        $this->assertNotNull($mechanism->date_transmitted);
        $this->assertSame('signed.pdf', $mechanism->currentAttachments()->firstWhere('kind', 'transmittal')->name);
        Notification::assertSentOnDemand(MechanismStatusChanged::class, function ($n, $channels, $notifiable) {
            return $notifiable->routes['mail'] === 'hr@agency.gov.ph'
                && count($n->attachments) === 3
                && str_contains(implode(' ', $n->lines), 'Print three (3) copies of the HR Mechanism for the signature of the Head of Agency');
        });
    }

    public function test_the_chief_may_send_it_back_to_the_action_officer_and_the_clock_keeps_running(): void
    {
        Storage::fake('local');
        $officer = $this->person(UserRole::PsedStaff);
        $chief = $this->person(UserRole::PsedChief);
        $mechanism = $this->submitted();
        $due = now()->addDays(10);
        $mechanism->update(['status' => MechanismStatus::Processing, 'action_officer_id' => $officer->id, 'acknowledged_at' => now(), 'due_at' => $due]);
        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/forward", $this->documents())->assertRedirect();

        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/return-to-officer", ['remarks' => 'Check the criteria.'])->assertForbidden();
        $this->actingAs($chief)->post("/mechanisms/{$mechanism->id}/return-to-officer", ['remarks' => ''])->assertSessionHasErrors('remarks');
        $this->actingAs($chief)->post("/mechanisms/{$mechanism->id}/return-to-officer", ['remarks' => 'Check the criteria.'])->assertRedirect();

        $mechanism->refresh();
        $this->assertSame(MechanismStatus::Processing, $mechanism->status);
        $this->assertEquals($due->toDateString(), $mechanism->due_at->toDateString());
        $this->assertDatabaseHas('hr_mechanism_events', ['hr_mechanism_id' => $mechanism->id, 'action' => 'returned_to_officer', 'remarks' => 'Check the criteria.']);

        // The officer re-evaluates and forwards it to the Chief again.
        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/forward", $this->documents())->assertRedirect();
        $this->assertSame(MechanismStatus::ReviewedByChief, $mechanism->fresh()->status);
    }

    public function test_the_signed_copies_are_approved_and_the_officer_then_records_the_distribution(): void
    {
        $officer = $this->person(UserRole::PsedStaff);
        $mechanism = $this->submitted();
        $mechanism->update(['status' => MechanismStatus::Approved, 'action_officer_id' => $officer->id]);

        $this->actingAs($this->person(UserRole::PsedStaff))->post("/mechanisms/{$mechanism->id}/distribute")->assertForbidden();
        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/distribute")->assertRedirect();

        $this->assertNotNull($mechanism->fresh()->date_distributed);
        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/distribute")->assertForbidden();
    }

    public function test_for_compliance_is_the_chiefs_decision_and_the_agency_answers_through_its_signed_link(): void
    {
        Storage::fake('local');
        Notification::fake();
        $officer = $this->person(UserRole::PsedStaff);
        $chief = $this->person(UserRole::PsedChief);
        $mechanism = $this->submitted();
        $mechanism->update(['status' => MechanismStatus::Processing, 'action_officer_id' => $officer->id, 'acknowledged_at' => now(), 'due_at' => now()->addDays(20)]);

        // The Action Officer only evaluates and forwards the reviewed mechanism and the checklist; they cannot send it back.
        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/compliance", ['remarks' => 'Not ready.'] + $this->letter())->assertForbidden();
        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/forward", $this->documents())->assertRedirect();
        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/compliance", ['remarks' => 'Not ready.'] + $this->letter())->assertForbidden();
        $this->assertNull($mechanism->fresh()->currentAttachments()->firstWhere('kind', 'transmittal'), 'no transmittal before the Chief decides');

        // The Chief decides.
        $this->actingAs($chief)->post("/mechanisms/{$mechanism->id}/compliance", ['remarks' => ''] + ['signed_checklist' => $this->signedPdf($chief->name)])->assertSessionHasErrors('remarks');

        $this->actingAs($chief)->post("/mechanisms/{$mechanism->id}/compliance", ['remarks' => 'The resolution is missing.', 'signed_checklist' => $this->signedPdf($chief->name)])->assertRedirect();

        // Marked For Compliance, but the agency hears nothing until the Action Officer sends the signed transmittal.
        $mechanism->refresh();
        $this->assertSame(MechanismStatus::ForCompliance, $mechanism->status);
        Notification::assertSentOnDemandTimes(MechanismStatusChanged::class, 0);
        $this->get(URL::signedRoute('track.show', $mechanism))->assertInertia(fn ($page) => $page->where('resubmitUrl', null));

        // The Action Officer handles the For Compliance transmittal the same way: generate it, then send the signed copy.
        $this->actingAs($chief)->post("/mechanisms/{$mechanism->id}/generate-transmittal", $this->letter())->assertForbidden();
        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/generate-transmittal", $this->letter())->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Transmittal_ForCompliance_'.$mechanism->tracking_code.'.docx', $mechanism->fresh()->currentAttachments()->firstWhere('kind', 'transmittal')->name);
        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/transmit", ['signed_transmittal' => UploadedFile::fake()->create('signed.pdf', 5)])->assertRedirect();

        $mechanism->refresh();
        $this->assertSame(MechanismStatus::TransmittalCompliance, $mechanism->status);
        $this->assertNotNull($mechanism->date_returned);
        Notification::assertSentOnDemand(MechanismStatusChanged::class, fn ($n) => $n->headline === 'For compliance' && count($n->attachments) === 3);

        // The agency, with no account, opens its link and uploads the corrected file.
        $this->get(URL::signedRoute('track.show', $mechanism))->assertOk()->assertInertia(fn ($page) => $page
            ->component('Public/Status')
            ->where('mechanism.status', 'Transmittal (For Compliance)')
            ->whereNot('resubmitUrl', null));

        $this->post(URL::signedRoute('track.resubmit', $mechanism), ['document' => UploadedFile::fake()->create('fixed.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')])->assertRedirect();

        $mechanism->refresh();
        $this->assertSame(MechanismStatus::Routed, $mechanism->status);
        $this->assertSame($officer->id, $mechanism->action_officer_id);
        $this->assertNull($mechanism->acknowledged_at);
    }

    public function test_the_chief_may_also_send_it_back_for_compliance(): void
    {
        Storage::fake('local');
        Notification::fake();
        $mechanism = $this->submitted();
        $mechanism->update(['status' => MechanismStatus::ReviewedByChief]);
        foreach (['reviewed', 'checklist'] as $kind) {
            $mechanism->attachments()->create(['kind' => $kind, 'path' => "x/{$kind}.pdf", 'name' => "{$kind}.pdf"]);
        }

        $chief = $this->person(UserRole::PsedChief);
        $this->actingAs($chief)->post("/mechanisms/{$mechanism->id}/compliance", ['remarks' => 'Needs the signed resolution.'] + $this->letter() + ['signed_checklist' => $this->signedPdf($chief->name)])->assertRedirect();

        $this->assertSame(MechanismStatus::ForCompliance, $mechanism->fresh()->status);
    }

    public function test_final_approval_comes_from_the_director_or_psed_only_after_the_chief(): void
    {
        $mechanism = $this->submitted();
        $director = $this->person(UserRole::Director);

        $this->actingAs($director)->post("/mechanisms/{$mechanism->id}/final-approve")->assertForbidden();

        $mechanism->update(['status' => MechanismStatus::TransmittalApproved]);
        $this->actingAs($director)->post("/mechanisms/{$mechanism->id}/final-approve")->assertRedirect();

        $mechanism->refresh();
        $this->assertSame(MechanismStatus::Approved, $mechanism->status);
        $this->assertNotNull($mechanism->date_approved);

        $onBehalf = $this->submitted();
        $onBehalf->update(['status' => MechanismStatus::TransmittalApproved]);
        $this->actingAs($this->person(UserRole::PsedStaff))->post("/mechanisms/{$onBehalf->id}/final-approve")->assertRedirect();
        $this->assertSame(MechanismStatus::Approved, $onBehalf->fresh()->status);
    }

    public function test_a_tracking_link_must_be_signed_and_the_lookup_needs_the_matching_email(): void
    {
        $mechanism = $this->submitted();

        $this->get("/track/{$mechanism->id}")->assertForbidden();
        $this->get(URL::signedRoute('track.show', $mechanism))->assertOk();

        $this->post('/track', ['tracking_code' => $mechanism->tracking_code, 'contact_email' => 'someone@else.gov.ph'])->assertSessionHasErrors('tracking_code');
        $this->post('/track', ['tracking_code' => strtolower($mechanism->tracking_code), 'contact_email' => 'HR@agency.gov.ph'])->assertRedirect(URL::signedRoute('track.show', $mechanism));
    }

    public function test_a_resubmission_is_refused_unless_the_mechanism_is_for_compliance(): void
    {
        $mechanism = $this->submitted();

        $this->post(URL::signedRoute('track.resubmit', $mechanism), ['document' => UploadedFile::fake()->create('x.pdf', 5)])->assertForbidden();
    }

    public function test_the_public_tracking_page_does_not_name_staff_or_show_internal_notes(): void
    {
        $officer = $this->person(UserRole::PsedStaff);
        $mechanism = $this->submitted();
        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/route", ['officer_id' => $officer->id, 'note' => 'Internal: rush']);
        auth()->logout();

        $page = $this->get(URL::signedRoute('track.show', $mechanism))->viewData('page')['props']['mechanism'];

        $this->assertStringNotContainsString($officer->name, json_encode($page));
        $this->assertStringNotContainsString('Internal: rush', json_encode($page));
        $this->assertCount(5, $page['steps']);
    }

    public function test_a_field_office_has_a_read_only_view_of_its_own_agencies(): void
    {
        $office = FieldOffice::factory()->create();
        $inOffice = Agency::factory()->create(['listed' => true, 'field_office_id' => $office->id]);
        $mechanism = HrMechanism::factory()->for($inOffice)->create();
        $elsewhere = HrMechanism::factory()->create();

        $fo = $this->person(UserRole::FieldOffice, $office);

        $this->actingAs($fo)->get('/mechanisms')->assertInertia(fn ($page) => $page->has('mechanisms.data', 1));
        $this->actingAs($fo)->getJson("/mechanisms/{$mechanism->id}")->assertOk();
        $this->actingAs($fo)->getJson("/mechanisms/{$elsewhere->id}")->assertForbidden();
        $this->actingAs($fo)->get("/mechanisms/{$mechanism->id}/file")->assertForbidden();
        $this->actingAs($fo)->post("/mechanisms/{$mechanism->id}/route", ['officer_id' => $fo->id])->assertForbidden();
        $this->actingAs($fo)->get('/dashboard')->assertInertia(fn ($page) => $page->component('Dashboard/FieldOffice'));
    }

    public function test_the_modal_endpoint_returns_the_details_with_what_the_viewer_may_do(): void
    {
        $mechanism = $this->submitted();

        $this->actingAs($this->person(UserRole::PsedStaff))->getJson("/mechanisms/{$mechanism->id}")
            ->assertOk()
            ->assertJsonPath('status', 'Received by PSED')
            ->assertJsonPath('can.route', true)
            ->assertJsonPath('can.acknowledge', false)
            ->assertJsonCount(5, 'steps');
    }

    public function test_a_returned_attempt_replaced_by_a_later_submission_leaves_the_dashboard_counts(): void
    {
        $agency = Agency::factory()->create();
        HrMechanism::factory()->for($agency)->status(MechanismStatus::TransmittalCompliance)->create(['date_submitted' => now()->subMonth()]);
        HrMechanism::factory()->for($agency)->status(MechanismStatus::Approved)->create(['date_submitted' => now()]);

        $counts = collect($this->actingAs($this->person(UserRole::PsedChief))->get('/dashboard')->viewData('page')['props']['counts'])->pluck('total', 'status');

        $this->assertSame(0, $counts['Transmittal (For Compliance)']);
        $this->assertSame(1, $counts['Approved']);
    }
}
