<?php

namespace Tests\Feature;

use App\Enums\MechanismStatus;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\HrMechanism;
use App\Notifications\MechanismStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** What happens when an agency submits its HR mechanism on the public page. */
class AgencySubmissionTest extends TestCase
{
    use RefreshDatabase;

    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private function docx(string $name = 'msp.docx', int $kb = 100): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, self::DOCX);
    }

    /** @param array<string, mixed> $override */
    private function submit(array $override = [], ?Agency $agency = null): \Illuminate\Testing\TestResponse
    {
        $agency ??= Agency::factory()->create(['listed' => true]);

        return $this->post('/submit', $override + [
            'agency_id' => $agency->id,
            'mechanism_type' => 'MSP',
            'contact_email' => 'hr@agency.gov.ph',
            'document' => $this->docx(),
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Notification::fake();
    }

    public function test_the_submission_is_saved_as_received_by_psed(): void
    {
        $agency = Agency::factory()->create(['listed' => true]);

        $this->submit([
            'title' => '2027 Merit Selection Plan',
            'contact_name' => 'Juan Dela Cruz',
            'note' => 'For your evaluation.',
        ], $agency)->assertSessionHasNoErrors();

        $mechanism = HrMechanism::firstOrFail();
        $this->assertSame($agency->id, $mechanism->agency_id);
        $this->assertSame('MSP', $mechanism->mechanism_type->value);
        $this->assertSame('2027 Merit Selection Plan', $mechanism->title);
        $this->assertSame('Juan Dela Cruz', $mechanism->contact_name);
        $this->assertSame(MechanismStatus::Received, $mechanism->status);
        $this->assertNotNull($mechanism->date_submitted);
        $this->assertNull($mechanism->action_officer_id, 'nobody is assigned until PSED routes it');
        $this->assertNull($mechanism->acknowledged_at);
        $this->assertNull($mechanism->due_at, 'the 20-day clock has not started');
    }

    public function test_the_docx_file_is_stored_privately_under_the_agency_with_its_original_name(): void
    {
        $agency = Agency::factory()->create(['listed' => true]);

        $this->submit(['document' => $this->docx('Our MSP 2027.docx')], $agency);

        $mechanism = HrMechanism::firstOrFail();
        $this->assertSame('Our MSP 2027.docx', $mechanism->document_name);
        $this->assertStringStartsWith('hr-mechanisms/'.$agency->id.'/', $mechanism->document_path);
        Storage::disk('local')->assertExists($mechanism->document_path);
    }

    public function test_it_gets_a_tracking_code_and_the_agency_lands_on_its_signed_tracking_page(): void
    {
        $response = $this->submit();

        $mechanism = HrMechanism::firstOrFail();
        $this->assertMatchesRegularExpression('/^HRM-\d{4}-\d{5}$/', $mechanism->tracking_code);
        $response->assertRedirect(URL::signedRoute('track.show', $mechanism));
        $response->assertSessionHas('submitted', true);

        $this->get(URL::signedRoute('track.show', $mechanism))->assertOk()->assertInertia(fn ($page) => $page
            ->component('Public/Status')
            ->where('mechanism.tracking_code', $mechanism->tracking_code)
            ->where('mechanism.status', 'Received by PSED')
            ->where('resubmitUrl', null));
    }

    public function test_each_submission_gets_its_own_tracking_code(): void
    {
        $this->submit();
        $this->submit();

        $this->assertSame(2, HrMechanism::distinct('tracking_code')->count('tracking_code'));
    }

    public function test_the_first_step_of_the_history_and_the_audit_log_are_recorded(): void
    {
        $this->submit(['note' => 'For your evaluation.']);

        $mechanism = HrMechanism::firstOrFail();
        $event = $mechanism->events()->firstOrFail();
        $this->assertSame('submitted', $event->action);
        $this->assertNull($event->user_id, 'an agency has no account');
        $this->assertNull($event->from_status);
        $this->assertSame('Received by PSED', $event->to_status);
        $this->assertSame('For your evaluation.', $event->remarks);

        $log = AuditLog::where('action', 'mechanism.submitted')->firstOrFail();
        $this->assertSame($mechanism->id, $log->subject_id);
        $this->assertSame($mechanism->tracking_code, $log->after['tracking_code']);
    }

    public function test_the_agency_is_emailed_an_acknowledgment_with_the_tracking_link(): void
    {
        $this->submit(['contact_email' => 'HR@Agency.gov.ph']);

        $mechanism = HrMechanism::firstOrFail();
        Notification::assertSentOnDemand(MechanismStatusChanged::class, function ($notification, $channels, $notifiable) use ($mechanism) {
            $mail = $notification->toMail($notifiable);

            return $notifiable->routes['mail'] === 'hr@agency.gov.ph'
                && $notification->headline === 'We received your HR mechanism'
                && str_contains($mail->subject, $mechanism->tracking_code)
                && $mail->actionUrl === URL::signedRoute('track.show', $mechanism);
        });
        Notification::assertSentOnDemandTimes(MechanismStatusChanged::class, 1);
    }

    public function test_a_failed_email_does_not_undo_the_submission(): void
    {
        Notification::shouldReceive('route')->andThrow(new \RuntimeException('SMTP down'));

        $this->submit()->assertSessionHasNoErrors();

        $this->assertSame(1, HrMechanism::count());
        $this->assertSame(MechanismStatus::Received, HrMechanism::firstOrFail()->status);
    }

    public function test_the_agency_can_find_it_again_with_the_tracking_code_and_its_email(): void
    {
        $this->submit(['contact_email' => 'hr@agency.gov.ph']);
        $mechanism = HrMechanism::firstOrFail();

        $this->post('/track', ['tracking_code' => $mechanism->tracking_code, 'contact_email' => 'HR@agency.gov.ph'])
            ->assertRedirect(URL::signedRoute('track.show', $mechanism));
    }

    public function test_only_a_docx_file_is_accepted(): void
    {
        $rejected = [
            'a PDF' => UploadedFile::fake()->create('msp.pdf', 10, 'application/pdf'),
            'an old .doc' => UploadedFile::fake()->create('msp.doc', 10, 'application/msword'),
            'a spreadsheet' => UploadedFile::fake()->create('msp.xlsx', 10, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            'a ZIP' => UploadedFile::fake()->create('msp.zip', 10, 'application/zip'),
            'a PDF renamed to .docx' => UploadedFile::fake()->create('msp.docx', 10, 'application/pdf'),
        ];

        foreach ($rejected as $what => $file) {
            $this->submit(['document' => $file])->assertSessionHasErrors('document', "$what was accepted");
        }

        $this->assertSame(0, HrMechanism::count());
        Notification::assertNothingSent();
    }

    public function test_the_error_for_a_wrong_file_type_says_docx(): void
    {
        $response = $this->submit(['document' => UploadedFile::fake()->create('msp.pdf', 10, 'application/pdf')]);

        $response->assertSessionHasErrors(['document' => 'The file must be a Word (.docx) file.']);
    }

    public function test_a_file_is_required_and_cannot_be_over_the_size_limit(): void
    {
        $this->submit(['document' => null])->assertSessionHasErrors('document');

        $tooBig = $this->docx('big.docx', config('hrmm.max_upload_kb') + 1);
        $this->submit(['document' => $tooBig])->assertSessionHasErrors('document');

        $this->assertSame(0, HrMechanism::count());
    }

    public function test_an_invalid_submission_stores_no_file_and_sends_no_email(): void
    {
        $this->submit(['contact_email' => 'not-an-email'])->assertSessionHasErrors('contact_email');

        $this->assertSame(0, HrMechanism::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        Notification::assertNothingSent();
    }

    public function test_it_must_name_a_kind_of_hr_mechanism(): void
    {
        $this->submit(['mechanism_type' => null])->assertSessionHasErrors('mechanism_type');
        $this->submit(['mechanism_type' => 'NOPE'])->assertSessionHasErrors('mechanism_type');
    }

    public function test_the_title_name_and_note_are_optional(): void
    {
        $this->submit()->assertSessionHasNoErrors();

        $mechanism = HrMechanism::firstOrFail();
        $this->assertNull($mechanism->title);
        $this->assertNull($mechanism->contact_name);
    }
}
