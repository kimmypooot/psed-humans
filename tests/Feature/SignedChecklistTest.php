<?php

namespace Tests\Feature;

use App\Enums\MechanismStatus;
use App\Enums\MechanismType;
use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\HrMechanism;
use App\Models\User;
use App\Notifications\MechanismStatusChanged;
use App\Support\MechanismTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SignsPdfs;
use Tests\TestCase;

/** The HR checklist the Action Officer forwards is replaced by one the PSED Chief signs with their DICT PNPKI certificate. */
class SignedChecklistTest extends TestCase
{
    use RefreshDatabase;
    use SignsPdfs;

    private User $chief;

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
        $this->chief = User::factory()->role(UserRole::PsedChief)->create(['name' => 'Banello Gabon']);
    }

    private function forReview(): HrMechanism
    {
        $agency = Agency::factory()->create(['name' => 'Municipal Government of Mayorga, Leyte', 'listed' => true]);
        $mechanism = HrMechanism::factory()->for($agency)->create(['status' => MechanismStatus::ReviewedByChief, 'mechanism_type' => MechanismType::Msp, 'contact_email' => 'hr@mayorga.gov.ph']);
        Storage::disk('local')->put('hr-mechanisms/staff-checklist.pdf', 'unsigned checklist');
        Storage::disk('local')->put('hr-mechanisms/reviewed.docx', 'reviewed');
        $mechanism->attachments()->create(['kind' => 'reviewed', 'path' => 'hr-mechanisms/reviewed.docx', 'name' => 'Reviewed MSP.docx']);
        $mechanism->attachments()->create(['kind' => 'checklist', 'path' => 'hr-mechanisms/staff-checklist.pdf', 'name' => 'Checklist (staff).pdf']);

        return $mechanism;
    }

    /** @return array<string, mixed> */
    private function letter(): array
    {
        return ['transmittal' => [
            'date' => '2027-03-09', 'addressee_name' => 'HON. JUAN D. DELA CRUZ', 'addressee_position' => 'Municipal Mayor',
            'addressee_address' => "Municipal Government of Mayorga\nProvince of Leyte", 'salutation' => 'Mayor Dela Cruz',
            'subject' => '2027 Merit Selection Plan (MSP)', 'signatory_id' => 66,
        ]];
    }

    private function approve(HrMechanism $mechanism, ?UploadedFile $signed)
    {
        return $this->actingAs($this->chief)->post("/mechanisms/{$mechanism->id}/chief-approve", $signed ? ['signed_checklist' => $signed] : []);
    }

    private function refused(HrMechanism $mechanism): void
    {
        $this->assertSame(MechanismStatus::ReviewedByChief, $mechanism->fresh()->status, 'nothing happened');
        $this->assertSame('Checklist (staff).pdf', $mechanism->fresh()->currentAttachments()->firstWhere('kind', 'checklist')->name, 'the checklist is not replaced');
        $this->assertNull($mechanism->fresh()->currentAttachments()->firstWhere('kind', 'transmittal'));
        Notification::assertNothingSentTo($this->chief);
    }

    public function test_the_chief_must_upload_a_checklist(): void
    {
        $mechanism = $this->forReview();

        $this->approve($mechanism, null)->assertSessionHasErrors(['signed_checklist' => 'Upload the HR checklist signed with your DICT PNPKI certificate.']);
        $this->approve($mechanism, UploadedFile::fake()->create('checklist.docx', 10))->assertSessionHasErrors('signed_checklist');
        $this->refused($mechanism);
    }

    public function test_an_unsigned_pdf_is_refused(): void
    {
        $mechanism = $this->forReview();

        $this->approve($mechanism, UploadedFile::fake()->createWithContent('checklist.pdf', "%PDF-1.4\n1 0 obj\n<< >>\nendobj\n%%EOF\n"))
            ->assertSessionHasErrors(['signed_checklist' => 'This PDF has no digital signature. Sign the HR checklist with your DICT PNPKI certificate, then upload the signed PDF.']);
        $this->refused($mechanism);
    }

    public function test_a_pdf_changed_after_it_was_signed_is_refused(): void
    {
        $mechanism = $this->forReview();

        $this->approve($mechanism, $this->signedPdf('BANELLO PABUA GABON', tamper: true))->assertSessionHasErrors('signed_checklist');
        $this->assertStringContainsString('changed after it was signed', session('errors')->first('signed_checklist'));
        $this->approve($mechanism, $this->signedPdf('BANELLO PABUA GABON', append: true))->assertSessionHasErrors('signed_checklist');
        $this->refused($mechanism);
    }

    public function test_a_signature_not_issued_by_dict_pnpki_is_refused(): void
    {
        $mechanism = $this->forReview();

        $this->approve($mechanism, $this->signedPdf('BANELLO PABUA GABON', 'Acme Certificates Inc'))->assertSessionHasErrors('signed_checklist');
        $this->assertStringContainsString('not by DICT PNPKI', session('errors')->first('signed_checklist'));
        $this->assertStringContainsString('Acme Certificates Inc', session('errors')->first('signed_checklist'), 'the message names the issuer found');
        $this->refused($mechanism);
    }

    public function test_it_must_be_signed_by_the_chief_themselves(): void
    {
        $mechanism = $this->forReview();

        $this->approve($mechanism, $this->signedPdf('KIM BENEDICK MACAWILE BANOYO'))->assertSessionHasErrors('signed_checklist');
        $this->assertStringContainsString('must be signed by you, Banello Gabon', session('errors')->first('signed_checklist'));
        $this->refused($mechanism);
    }

    public function test_a_valid_signature_replaces_the_checklist_and_the_agency_gets_the_signed_one(): void
    {
        $mechanism = $this->forReview();

        $this->approve($mechanism, $this->signedPdf('BANELLO PABUA GABON', name: 'HR Checklist - Mayorga (signed).pdf'))->assertRedirect()->assertSessionHasNoErrors();

        $mechanism->refresh();
        $this->assertSame(MechanismStatus::Reviewed, $mechanism->status);
        Notification::assertNothingSent();

        $checklist = $mechanism->currentAttachments()->firstWhere('kind', 'checklist');
        $this->assertSame('HR Checklist - Mayorga (signed).pdf', $checklist->name);
        $this->assertSame('BANELLO PABUA GABON', $checklist->signed_by);
        $this->assertStringContainsString('DICT PNPKI Certificate Authority', $checklist->signer_issuer);
        $this->assertNotNull($checklist->signed_checked_at);
        $this->assertSame($this->chief->id, $checklist->uploaded_by);

        // The Action Officer's copy is kept in the history, but it is no longer the checklist.
        $this->assertSame(['Checklist (staff).pdf', 'HR Checklist - Mayorga (signed).pdf'], $mechanism->attachments()->where('kind', 'checklist')->pluck('name')->all());

        $this->assertDatabaseHas('hr_mechanism_events', ['hr_mechanism_id' => $mechanism->id, 'action' => 'checklist_signed', 'user_id' => $this->chief->id]);

        // Only when the Action Officer sends the signed transmittal does the agency get the signed checklist.
        $officer = User::factory()->role(UserRole::PsedStaff)->create();
        $mechanism->update(['action_officer_id' => $officer->id]);
        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/generate-transmittal", $this->letter())->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/transmit", ['signed_transmittal' => UploadedFile::fake()->create('signed.pdf', 20)])->assertRedirect();
        $this->assertSame(MechanismStatus::TransmittalApproved, $mechanism->fresh()->status);

        Notification::assertSentOnDemand(MechanismStatusChanged::class, fn ($n) => $n->mechanism->is($mechanism)
            && collect($n->attachments)->firstWhere('kind', 'checklist')->name === 'HR Checklist - Mayorga (signed).pdf'
            && collect($n->attachments)->pluck('kind')->sort()->values()->all() === ['checklist', 'reviewed', 'transmittal']);
    }

    public function test_the_same_applies_when_the_chief_sends_it_back_for_compliance(): void
    {
        $mechanism = $this->forReview();
        $post = fn (?UploadedFile $signed) => $this->actingAs($this->chief)->post("/mechanisms/{$mechanism->id}/compliance", ['remarks' => 'The resolution is missing.'] + ($signed ? ['signed_checklist' => $signed] : []));

        $post(null)->assertSessionHasErrors('signed_checklist');
        $post($this->signedPdf('SOMEONE ELSE ENTIRELY'))->assertSessionHasErrors('signed_checklist');
        $this->assertSame(MechanismStatus::ReviewedByChief, $mechanism->fresh()->status);

        $post($this->signedPdf('BANELLO PABUA GABON'))->assertRedirect();

        $mechanism->refresh();
        $this->assertSame(MechanismStatus::ForCompliance, $mechanism->status);
        $this->assertSame('BANELLO PABUA GABON', $mechanism->currentAttachments()->firstWhere('kind', 'checklist')->signed_by);
    }

    public function test_the_signature_shows_on_the_document_but_not_to_the_agency(): void
    {
        $mechanism = $this->forReview();
        $this->approve($mechanism, $this->signedPdf('BANELLO PABUA GABON'))->assertRedirect();

        $documents = collect($this->actingAs($this->chief)->getJson("/mechanisms/{$mechanism->id}")->assertOk()->json('attachments'))->keyBy('kind');
        $this->assertSame('BANELLO PABUA GABON', $documents['checklist']['signed_by']);
        $this->assertNull($documents['reviewed']['signed_by']);

        $this->assertNotContains('checklist_signed', collect(MechanismTimeline::history($mechanism->fresh(), true))->pluck('action')->all(), 'the agency is not shown the certificate details');
        $this->assertContains('checklist_signed', collect(MechanismTimeline::history($mechanism->fresh(), false))->pluck('action')->all());
    }

    public function test_forwarding_to_the_chief_does_not_need_a_signature(): void
    {
        $officer = User::factory()->role(UserRole::PsedStaff)->create();
        $agency = Agency::factory()->create(['listed' => true]);
        $mechanism = HrMechanism::factory()->for($agency)->create(['status' => MechanismStatus::Processing, 'action_officer_id' => $officer->id]);

        $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/forward", [
            'reviewed' => UploadedFile::fake()->create('reviewed.docx', 10),
            'checklist' => UploadedFile::fake()->create('checklist.pdf', 10),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(MechanismStatus::ReviewedByChief, $mechanism->fresh()->status);
        $this->assertNull($mechanism->fresh()->currentAttachments()->firstWhere('kind', 'checklist')->signed_by);
    }
}
