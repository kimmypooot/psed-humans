<?php

namespace App\Actions\HrMechanisms;

use App\Enums\MechanismStatus;
use App\Enums\MechanismType;
use App\Models\Agency;
use App\Models\HrMechanism;
use App\Notifications\MechanismStatusChanged;
use App\Support\StaffNotices;
use App\Support\Turnaround;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * An agency submits an HR mechanism from the public page: it is Received by PSED and the agency is
 * sent an acknowledgment of receipt. Or the agency answers a request for compliance with a corrected file.
 */
class SubmitHrMechanism
{
    public function __invoke(Agency $agency, MechanismType $type, ?string $title, string $email, ?string $contactName, ?string $note, UploadedFile $document): HrMechanism
    {
        $path = $document->store('hr-mechanisms/'.$agency->id, 'local');

        $mechanism = DB::transaction(function () use ($agency, $type, $title, $email, $contactName, $note, $document, $path) {
            $mechanism = HrMechanism::create([
                'agency_id' => $agency->id,
                'tracking_code' => HrMechanism::nextTrackingCode(),
                'mechanism_type' => $type,
                'title' => $title,
                'copies' => 'eCOPY',
                'document_path' => $path,
                'document_name' => $document->getClientOriginalName(),
                'status' => MechanismStatus::Received,
                'date_submitted' => now(),
                'contact_email' => mb_strtolower($email),
                'contact_name' => $contactName,
            ]);

            $mechanism->recordEvent('submitted', null, null, MechanismStatus::Received, $note);

            return $mechanism;
        });

        $mechanism->load('agency');
        StaffNotices::submitted($mechanism);
        $this->tell(
            $mechanism,
            'We received your HR mechanism',
            'This is to acknowledge receipt of your HR mechanism by the Policies and Systems Evaluation Division (PSED). Its status is Received by PSED.',
            ['A PSED staff member will route it to an Action Officer, who will evaluate it. We will email you when something needs your attention, or you can use the link below at any time.'],
        );

        return $mechanism;
    }

    /**
     * Replaces the file of a mechanism sent back for compliance. It goes back to the Action Officer it
     * was with (or to PSED to route, if it never had one), and the 20-day clock starts again when
     * it is back with the officer (or is routed).
     */
    public function resubmit(HrMechanism $mechanism, UploadedFile $document, ?string $note): HrMechanism
    {
        $path = $document->store('hr-mechanisms/'.$mechanism->agency_id, 'local');
        $from = $mechanism->status;
        $to = $mechanism->action_officer_id ? MechanismStatus::Routed : MechanismStatus::Received;

        DB::transaction(function () use ($mechanism, $document, $path, $note, $from, $to) {
            $mechanism->update([
                'document_path' => $path,
                'document_name' => $document->getClientOriginalName(),
                'status' => $to,
                'date_submitted' => now(),
                'routed_at' => $to === MechanismStatus::Routed ? now() : null,
                'acknowledged_at' => null,
                // Back with its officer: the 20-day clock starts again now. Otherwise it starts when PSED routes it.
                'due_at' => $to === MechanismStatus::Routed ? Turnaround::dueFrom(now()) : null,
                'date_returned' => null,
                'date_evaluated' => null,
                'date_reviewed' => null,
                'remarks' => null,
            ]);

            $mechanism->recordEvent('resubmitted', null, $from, $to, $note);
        });

        $mechanism->load('agency');
        StaffNotices::resubmitted($mechanism);
        $this->tell($mechanism, 'We received your corrected file', 'This is to acknowledge receipt of the corrected HR mechanism. PSED will evaluate it again.');

        return $mechanism;
    }

    /**
     * @param  list<string>  $lines
     */
    private function tell(HrMechanism $mechanism, string $headline, string $body, array $lines = []): void
    {
        if (! $mechanism->contact_email) {
            return;
        }

        // A failed email never undoes a submission.
        try {
            Notification::route('mail', $mechanism->contact_email)->notify(new MechanismStatusChanged($mechanism, $headline, $body, $lines));
        } catch (Throwable $e) {
            Log::warning('HR mechanism email failed', ['mechanism' => $mechanism->id, 'message' => $e->getMessage()]);
        }
    }
}
