<?php

namespace App\Actions\HrMechanisms;

use App\Enums\MechanismStatus;
use App\Enums\UserRole;
use App\Models\ActionOfficer;
use App\Models\HrMechanism;
use App\Models\HrMechanismAttachment;
use App\Models\User;
use App\Notifications\MechanismForwardedToChief;
use App\Notifications\MechanismStatusChanged;
use App\Support\StaffNotices;
use App\Support\Transmittal;
use App\Support\Turnaround;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

/**
 * What PSED does with a submission, in order:
 *  route (PSED staff) -> acknowledge (Action Officer, the 20-day clock starts) -> attach the three
 *  documents and forward (Action Officer) -> review (PSED Chief: approve, which emails the agency with
 *  the documents) -> final approval once the signed copies are in.
 * At any point of processing or Chief review it can instead be sent back For Compliance, with the
 * same three documents emailed to the agency.
 */
class ProcessHrMechanism
{
    /** The PSED staff member routes it to an Action Officer (who may be themselves). */
    public function route(HrMechanism $mechanism, User $by, User $officer, ?string $note): HrMechanism
    {
        $from = $mechanism->status;

        DB::transaction(function () use ($mechanism, $by, $officer, $note, $from) {
            $record = ActionOfficer::forUser($officer);
            $initials = mb_substr($record->initials, 0, 20);

            $mechanism->update([
                'action_officer_id' => $officer->id,
                'processor' => $initials,
                'status' => MechanismStatus::Routed,
                'routed_at' => now(),
                'acknowledged_at' => null,
                'due_at' => null,
            ]);
            $mechanism->officers()->sync([$record->id => ['position' => 1]]);

            $mechanism->recordEvent('routed', $by, $from, MechanismStatus::Routed, trim('To '.$officer->name.($note ? '. '.$note : '')));
        });

        StaffNotices::routed($mechanism, $by, $officer);

        return $mechanism;
    }

    /** The Action Officer acknowledges receipt: the 20-day turnaround starts and it is being processed. */
    public function acknowledge(HrMechanism $mechanism, User $by): HrMechanism
    {
        $now = now();

        DB::transaction(function () use ($mechanism, $by, $now) {
            $from = $mechanism->status;
            $mechanism->update([
                'status' => MechanismStatus::Processing,
                'acknowledged_at' => $now,
                'date_processed' => $now,
                'due_at' => Turnaround::dueFrom($now),
            ]);

            $mechanism->recordEvent('acknowledged', $by, $from, MechanismStatus::Processing, 'Turnaround: '.Turnaround::days().' working days, until '.$mechanism->due_at->format('M j, Y').'.');
        });

        return $mechanism;
    }

    /**
     * Stores the reviewed HR mechanism and the HR checklist. Pass either; a kind not passed keeps the
     * file it has. The transmittal is not uploaded: it is generated when the Chief approves or it is sent
     * back for compliance.
     *
     * @param  array<string, UploadedFile|null>  $files  keyed by reviewed, checklist
     */
    public function attach(HrMechanism $mechanism, User $by, array $files): void
    {
        foreach (HrMechanismAttachment::UPLOADED as $kind) {
            $file = $files[$kind] ?? null;

            if (! $file instanceof UploadedFile) {
                continue;
            }

            $mechanism->attachments()->create([
                'kind' => $kind,
                'path' => $file->store('hr-mechanisms/'.$mechanism->agency_id.'/'.$mechanism->id, 'local'),
                'name' => $file->getClientOriginalName(),
                'uploaded_by' => $by->id,
            ]);
        }
    }

    /**
     * The Action Officer finished evaluating: the reviewed HR mechanism and the HR checklist go with it to the PSED Chief.
     *
     * @throws RuntimeException when a document is missing
     */
    public function forward(HrMechanism $mechanism, User $by, ?string $note): HrMechanism
    {
        $this->requireDocuments($mechanism);

        DB::transaction(function () use ($mechanism, $by, $note) {
            $from = $mechanism->status;
            $mechanism->update(['status' => MechanismStatus::ForChiefReview, 'date_evaluated' => now()]);
            $mechanism->recordEvent('forwarded', $by, $from, MechanismStatus::ForChiefReview, $note);
        });

        $this->notifyChief($mechanism, $by);

        return $mechanism;
    }

    /**
     * Tells every PSED Chief it is waiting for them: in the bell and by email. A failed notice never undoes the
     * forwarding (the Chief still sees it on the dashboard), but it is logged.
     */
    private function notifyChief(HrMechanism $mechanism, User $by): void
    {
        $mechanism->loadMissing('agency');

        foreach (User::where('role', UserRole::PsedChief)->get() as $chief) {
            try {
                $chief->notify(new MechanismForwardedToChief($mechanism, $by));
            } catch (Throwable $e) {
                Log::warning('Notice to the PSED Chief failed', ['mechanism' => $mechanism->id, 'chief' => $chief->id, 'message' => $e->getMessage()]);
            }
        }
    }

    /**
     * Not compliant: back to the agency with the reviewed mechanism and checklist, and a transmittal made now
     * (the For Compliance letter), by email.
     *
     * @param  array<string, mixed>  $transmittal  what the letter says; see Transmittal::rules
     *
     * @throws RuntimeException when a document is missing
     */
    public function returnForCompliance(HrMechanism $mechanism, User $by, string $remarks, array $transmittal): HrMechanism
    {
        $this->requireDocuments($mechanism);

        $mechanism = DB::transaction(function () use ($mechanism, $by, $remarks, $transmittal) {
            $this->writeTransmittal($mechanism, $by, 'compliance', $transmittal);
            $from = $mechanism->status;
            $mechanism->update([
                'status' => MechanismStatus::ForCompliance,
                'date_processed' => $mechanism->date_processed ?? now(),
                'date_reviewed' => $by->isChief() ? now() : $mechanism->date_reviewed,
                'date_returned' => now(),
                'remarks' => $remarks,
            ]);
            $mechanism->recordEvent('returned', $by, $from, MechanismStatus::ForCompliance, $remarks);

            return $mechanism;
        });

        $this->tell(
            $mechanism,
            'For compliance',
            'Your HR mechanism needs corrections before PSED can approve it.',
            [$remarks, 'The transmittal, the reviewed HR mechanism and the HR checklist are attached. Correct the HR mechanism and upload it again from your tracking page.'],
            true,
        );
        StaffNotices::returned($mechanism, $by, $remarks);

        return $mechanism;
    }

    /**
     * The PSED Chief approves: the For Approval transmittal is made now, and the agency is emailed it with the
     * reviewed HR mechanism and the HR checklist, and told to print three copies for signing.
     *
     * @param  array<string, mixed>  $transmittal  what the letter says; see Transmittal::rules
     */
    public function chiefApprove(HrMechanism $mechanism, User $by, ?string $note, array $transmittal): HrMechanism
    {
        $this->requireDocuments($mechanism);

        $mechanism = DB::transaction(function () use ($mechanism, $by, $note, $transmittal) {
            $this->writeTransmittal($mechanism, $by, 'approval', $transmittal);
            $from = $mechanism->status;
            $mechanism->update(['status' => MechanismStatus::ReviewedByChief, 'date_reviewed' => now(), 'remarks' => $note]);
            $mechanism->recordEvent('reviewed', $by, $from, MechanismStatus::ReviewedByChief, $note);

            return $mechanism;
        });

        $this->tell(
            $mechanism,
            'Approved by the PSED Chief',
            'Your HR mechanism was reviewed and approved by the PSED Chief.',
            [
                'Print three (3) copies of the HR Mechanism for the signature of the Head of Agency, then submit the signed copies to the CSC Regional Office VIII for final approval.',
                'The reviewed HR mechanism, the transmittal and the HR checklist are attached.',
            ],
            true,
        );
        StaffNotices::reviewed($mechanism, $by);

        return $mechanism;
    }

    /** The signed copies reached the Regional Office and the Director IV (or PSED for them) approves. */
    public function finalApprove(HrMechanism $mechanism, User $by, ?string $note): HrMechanism
    {
        $mechanism = DB::transaction(function () use ($mechanism, $by, $note) {
            $from = $mechanism->status;
            $mechanism->update([
                'status' => MechanismStatus::Approved,
                'date_submitted_to_rd' => $mechanism->date_submitted_to_rd ?? now(),
                'date_approved' => now(),
                'remarks' => $note,
            ]);
            $mechanism->recordEvent('approved', $by, $from, MechanismStatus::Approved, $note);

            return $mechanism;
        });

        $this->tell($mechanism, 'Approved', 'Your HR mechanism has been approved by the Regional Office.');
        StaffNotices::approved($mechanism, $by);

        return $mechanism;
    }

    /** @throws RuntimeException */
    private function requireDocuments(HrMechanism $mechanism): void
    {
        if (! $mechanism->hasReviewDocuments()) {
            throw new RuntimeException('Attach the reviewed HR mechanism and the HR checklist first.');
        }
    }

    /**
     * Makes the transmittal letter from what PSED filled in and keeps it with the other documents. The head it
     * is addressed to is remembered on the agency, so the next letter to them is already filled in.
     *
     * @param  array<string, mixed>  $data
     */
    private function writeTransmittal(HrMechanism $mechanism, User $by, string $type, array $data): void
    {
        $mechanism->loadMissing('agency');
        Transmittal::store($mechanism, $type, $data, $by);
        $mechanism->agency->update(['head_name' => $data['addressee_name'], 'head_position' => $data['addressee_position']]);
    }

    /**
     * Emails the agency. A failed email never undoes a step, but it is recorded in the history so
     * PSED knows the agency has not got it. Rows from the old workbook have no address.
     *
     * @param  list<string>  $lines
     */
    private function tell(HrMechanism $mechanism, string $headline, string $body, array $lines = [], bool $withDocuments = false): void
    {
        if (! $mechanism->contact_email) {
            return;
        }

        $mechanism->loadMissing('agency');

        try {
            Notification::route('mail', $mechanism->contact_email)->notify(new MechanismStatusChanged(
                $mechanism,
                $headline,
                $body,
                $lines,
                $withDocuments ? $mechanism->currentAttachments() : [],
            ));
        } catch (Throwable $e) {
            Log::warning('HR mechanism email failed', ['mechanism' => $mechanism->id, 'message' => $e->getMessage()]);
            $mechanism->recordEvent('email_failed', null, null, null, 'The email to '.$mechanism->contact_email.' could not be sent.');
        }
    }
}
