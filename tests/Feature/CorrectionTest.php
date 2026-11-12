<?php

namespace Tests\Feature;

use App\Enums\MechanismStatus;
use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\HrMechanism;
use App\Models\User;
use App\Notifications\MechanismStatusChanged;
use App\Support\MechanismTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** After the agency was emailed, the Action Officer can send corrected documents. */
class CorrectionTest extends TestCase
{
    use RefreshDatabase;

    private User $officer;

    private HrMechanism $mechanism;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();

        $this->officer = User::factory()->role(UserRole::PsedStaff)->create();
        $this->mechanism = HrMechanism::factory()->for(Agency::factory()->create(['listed' => true]))->create([
            'status' => MechanismStatus::TransmittalApproved,
            'action_officer_id' => $this->officer->id,
            'contact_email' => 'hr@agency.gov.ph',
            'date_transmitted' => now()->subDay(),
        ]);
        foreach (['reviewed' => 'reviewed.docx', 'checklist' => 'checklist.pdf', 'transmittal' => 'wrong-signed.pdf'] as $kind => $name) {
            Storage::disk('local')->put("hr-mechanisms/x/{$name}", 'file');
            $this->mechanism->attachments()->create(['kind' => $kind, 'path' => "hr-mechanisms/x/{$name}", 'name' => $name]);
        }
    }

    private function correct(User $by, array $data = [])
    {
        return $this->actingAs($by)->post("/mechanisms/{$this->mechanism->id}/correct", $data);
    }

    private function corrected(array $extra = []): array
    {
        return ['signed_transmittal' => UploadedFile::fake()->create('right-signed.pdf', 10), 'remarks' => 'The wrong scan was attached.'] + $extra;
    }

    private function names(): array
    {
        return $this->mechanism->fresh()->currentAttachments()->pluck('name', 'kind')->all();
    }

    public function test_the_action_officer_sends_the_corrected_transmittal_and_the_agency_is_told_to_use_it(): void
    {
        $before = $this->mechanism->fresh()->date_transmitted;

        $this->correct($this->officer, $this->corrected())->assertRedirect()->assertSessionHasNoErrors();

        $m = $this->mechanism->fresh();
        $this->assertSame(MechanismStatus::TransmittalApproved, $m->status, 'the status does not change');
        $this->assertTrue($m->date_transmitted->gt($before));
        $this->assertSame(['reviewed' => 'reviewed.docx', 'checklist' => 'checklist.pdf', 'transmittal' => 'right-signed.pdf'], $this->names());
        $this->assertSame(['wrong-signed.pdf', 'right-signed.pdf'], $m->attachments()->where('kind', 'transmittal')->pluck('name')->all(), 'the earlier file is kept');
        $this->assertDatabaseHas('hr_mechanism_events', ['hr_mechanism_id' => $m->id, 'action' => 'retransmitted', 'user_id' => $this->officer->id, 'remarks' => 'The wrong scan was attached.']);

        Notification::assertSentOnDemand(MechanismStatusChanged::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'hr@agency.gov.ph'
            && $n->headline === 'Your HR mechanism was reviewed (corrected documents)'
            && count($n->attachments) === 3
            && collect($n->attachments)->firstWhere('kind', 'transmittal')->name === 'right-signed.pdf'
            && str_contains(implode(' ', $n->lines), 'replace the ones PSED sent you earlier'));
    }

    public function test_the_reviewed_hr_mechanism_can_be_replaced_too(): void
    {
        $this->correct($this->officer, $this->corrected(['reviewed' => UploadedFile::fake()->create('reviewed-v2.docx', 10)]))->assertRedirect();

        $this->assertSame('reviewed-v2.docx', $this->names()['reviewed']);
        $this->assertSame('checklist.pdf', $this->names()['checklist'], 'the Chief\'s checklist is not touched');
    }

    public function test_a_file_and_a_reason_are_needed_and_only_the_action_officer_may_correct(): void
    {
        $this->correct($this->officer, ['remarks' => 'The wrong scan was attached.'])->assertSessionHasErrors(['signed_transmittal' => 'Upload the corrected transmittal, signed by the Regional Director.']);
        $this->correct($this->officer, ['signed_transmittal' => UploadedFile::fake()->create('x.pdf', 10), 'remarks' => ''])->assertSessionHasErrors(['remarks' => 'Say what was wrong and what was corrected.']);
        $this->correct($this->officer, ['signed_transmittal' => UploadedFile::fake()->create('x.exe', 10), 'remarks' => 'The wrong scan was attached.'])->assertSessionHasErrors('signed_transmittal');

        foreach ([UserRole::PsedChief, UserRole::PsedStaff, UserRole::Director] as $role) {
            $this->correct(User::factory()->role($role)->create(), $this->corrected())->assertForbidden();
        }

        $this->assertSame('wrong-signed.pdf', $this->names()['transmittal']);
        Notification::assertNothingSent();
    }

    public function test_the_button_shows_only_between_sending_and_the_end_of_the_step(): void
    {
        $can = fn () => $this->actingAs($this->officer)->getJson("/mechanisms/{$this->mechanism->id}")->json('can.correct');

        $this->assertTrue($can());

        $this->mechanism->update(['status' => MechanismStatus::TransmittalCompliance]);
        $this->assertTrue($can(), 'also after For Compliance was sent');

        foreach ([MechanismStatus::Reviewed, MechanismStatus::ForCompliance, MechanismStatus::Routed, MechanismStatus::Approved] as $status) {
            $this->mechanism->update(['status' => $status]);
            $this->assertFalse($can(), $status->value);
            $this->correct($this->officer, $this->corrected())->assertForbidden();
        }
    }

    public function test_the_for_compliance_email_keeps_what_needs_correcting(): void
    {
        $this->mechanism->update(['status' => MechanismStatus::TransmittalCompliance, 'remarks' => 'The resolution is missing.']);

        $this->correct($this->officer, $this->corrected())->assertRedirect();

        Notification::assertSentOnDemand(MechanismStatusChanged::class, fn ($n) => $n->headline === 'For compliance (corrected documents)'
            && in_array('The resolution is missing.', $n->lines, true)
            && str_contains(implode(' ', $n->lines), 'replace the ones PSED sent you earlier'));
    }

    public function test_the_agency_sees_a_correction_was_sent_but_not_why(): void
    {
        $this->correct($this->officer, $this->corrected())->assertRedirect();

        $public = collect(MechanismTimeline::history($this->mechanism->fresh(), true))->firstWhere('action', 'retransmitted');
        $this->assertSame('Corrected documents sent to the agency', $public['title']);
        $this->assertNull($public['remarks']);

        $inside = collect(MechanismTimeline::history($this->mechanism->fresh(), false))->firstWhere('action', 'retransmitted');
        $this->assertSame('The wrong scan was attached.', $inside['remarks']);
    }
}
