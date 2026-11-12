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
 * What PSED does with a submission, in the order the PSED Chief approved:
 *  route (PSED staff; the 20-day clock starts) -> acknowledge (Action Officer) -> attach the reviewed
 *  mechanism and the checklist and forward (Action Officer) -> review (PSED Chief: approve, mark For
 *  Compliance, or send back to the officer; the Chief signs the checklist) -> generate the transmittal
 *  (Action Officer) -> transmit (Action Officer, once the Regional Director signed the printed
 *  transmittal; this is when the agency is emailed) -> final approval once the Head of
 *  Agency's signed copies are in -> distribute the approved copies.
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
            $now = now();

            $mechanism->update([
                'action_officer_id' => $officer->id,
                'processor' => $initials,
                'status' => MechanismStatus::Routed,
                'routed_at' => $now,
                'acknowledged_at' => null,
                // The 20-day clock starts at routing. Routing to another officer does not give the division more time.
                'due_at' => $mechanism->due_at ?? Turnaround::dueFrom($now),
                'date_processed' => $mechanism->date_processed ?? $now,
            ]);
            $mechanism->officers()->sync([$record->id => ['position' => 1]]);

            $mechanism->recordEvent('routed', $by, $from, MechanismStatus::Routed, trim('To '.$officer->name.'. Turnaround: '.Turnaround::days().' working days, until '.$mechanism->due_at->format('M j, Y').'.'.($note ? ' '.$note : '')));
        });

        StaffNotices::routed($mechanism, $by, $officer);

        return $mechanism;
    }

    /** The Action Officer acknowledges receipt and starts processing. The 20-day clock already started when it was routed. */
    public function acknowledge(HrMechanism $mechanism, User $by): HrMechanism
    {
        $now = now();

        DB::transaction(function () use ($mechanism, $by, $now) {
            $from = $mechanism->status;
            $mechanism->update([
                'status' => MechanismStatus::Processing,
                'acknowledged_at' => $now,
            ]);

            $mechanism->recordEvent('acknowledged', $by, $from, MechanismStatus::Processing, 'Target date: '.$mechanism->due_at?->format('M j, Y').'.');
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
            $mechanism->update(['status' => MechanismStatus::ReviewedByChief, 'date_evaluated' => now()]);
            $mechanism->recordEvent('forwarded', $by, $from, MechanismStatus::ReviewedByChief, $note);
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
     * Not approved: the Chief sends it back to the Action Officer to evaluate again. The 20-day clock keeps running; the
     * officer forwards it to the Chief again when done.
     */
    public function returnToOfficer(HrMechanism $mechanism, User $by, string $remarks): HrMechanism
    {
        DB::transaction(function () use ($mechanism, $by, $remarks) {
            $from = $mechanism->status;
            $mechanism->update(['status' => MechanismStatus::Processing, 'date_evaluated' => null]);
            $mechanism->recordEvent('returned_to_officer', $by, $from, MechanismStatus::Processing, $remarks);
        });

        StaffNotices::returnedToOfficer($mechanism, $by, $remarks);

        return $mechanism;
    }

    /**
     * The Chief marks it For Compliance: the corrections are noted and the HR checklist they signed replaces the officer's.
     * Nothing goes to the agency yet: the Action Officer generates the For Compliance transmittal, prints it for the
     * Regional Director's signature, then transmits it.
     *
     * @param  array<string, mixed>  $signature  what PdfSignature found in the signed checklist
     *
     * @throws RuntimeException when a document is missing
     */
    public function returnForCompliance(HrMechanism $mechanism, User $by, string $remarks, UploadedFile $signedChecklist, array $signature): HrMechanism
    {
        $this->requireDocuments($mechanism);

        $mechanism = DB::transaction(function () use ($mechanism, $by, $remarks, $signedChecklist, $signature) {
            $this->replaceChecklist($mechanism, $by, $signedChecklist, $signature);
            $from = $mechanism->status;
            $mechanism->update([
                'status' => MechanismStatus::ForCompliance,
                'date_processed' => $mechanism->date_processed ?? now(),
                'date_reviewed' => now(),
                'remarks' => $remarks,
            ]);
            $mechanism->recordEvent('marked_for_compliance', $by, $from, MechanismStatus::ForCompliance, $remarks);

            return $mechanism;
        });

        StaffNotices::readyToTransmit($mechanism, $by, forCompliance: true);

        return $mechanism;
    }

    /**
     * The PSED Chief approves: the HR checklist they signed replaces the officer's. The status is Reviewed. The agency is not
     * emailed yet: the Action Officer generates the For Approval transmittal, prints it for the Regional Director's
     * signature, then transmits it.
     *
     * @param  array<string, mixed>  $signature  what PdfSignature found in the signed checklist
     */
    public function chiefApprove(HrMechanism $mechanism, User $by, ?string $note, UploadedFile $signedChecklist, array $signature): HrMechanism
    {
        $this->requireDocuments($mechanism);

        $mechanism = DB::transaction(function () use ($mechanism, $by, $note, $signedChecklist, $signature) {
            $this->replaceChecklist($mechanism, $by, $signedChecklist, $signature);
            $from = $mechanism->status;
            $mechanism->update(['status' => MechanismStatus::Reviewed, 'date_reviewed' => now(), 'remarks' => $note]);
            $mechanism->recordEvent('reviewed', $by, $from, MechanismStatus::Reviewed, $note);

            return $mechanism;
        });

        StaffNotices::readyToTransmit($mechanism, $by, forCompliance: false);

        return $mechanism;
    }

    /**
     * The Action Officer makes the transmittal letter for the Chief's decision (For Approval, or For Compliance when the Chief
     * marked it so) from what they filled in. It can be made again until it is sent; the latest one is the one to print.
     *
     * @param  array<string, mixed>  $transmittal  what the letter says; see Transmittal::rules
     */
    public function generateTransmittal(HrMechanism $mechanism, User $by, array $transmittal): HrMechanism
    {
        $type = Transmittal::typeFor($mechanism);

        DB::transaction(function () use ($mechanism, $by, $type, $transmittal) {
            $this->writeTransmittal($mechanism, $by, $type, $transmittal);
            $mechanism->recordEvent('transmittal_generated', $by, $mechanism->status, $mechanism->status, 'The '.Transmittal::TYPES[$type].' transmittal was generated.');
        });

        return $mechanism;
    }

    /**
     * The Regional Director signed the printed transmittal. The Action Officer records it with the signed copy: the status
     * becomes Transmittal (Approved) or Transmittal (For Compliance), and the agency is emailed the reviewed HR mechanism,
     * the signed transmittal and the HR checklist.
     *
     * @throws RuntimeException when a document is missing or the transmittal was not generated
     */
    public function transmit(HrMechanism $mechanism, User $by, UploadedFile $signedTransmittal, ?string $note): HrMechanism
    {
        $this->requireDocuments($mechanism);

        if (! $mechanism->hasGeneratedTransmittal()) {
            throw new RuntimeException('Generate the transmittal first, then print it for the Regional Director\'s signature.');
        }

        $forCompliance = $mechanism->status === MechanismStatus::ForCompliance;
        $to = $forCompliance ? MechanismStatus::TransmittalCompliance : MechanismStatus::TransmittalApproved;

        $mechanism = DB::transaction(function () use ($mechanism, $by, $signedTransmittal, $note, $forCompliance, $to) {
            $mechanism->attachments()->create([
                'kind' => 'transmittal',
                'path' => $signedTransmittal->store('hr-mechanisms/'.$mechanism->agency_id.'/'.$mechanism->id, 'local'),
                'name' => $signedTransmittal->getClientOriginalName(),
                'uploaded_by' => $by->id,
            ]);

            $from = $mechanism->status;
            $mechanism->update(['status' => $to, 'date_transmitted' => now()] + ($forCompliance ? ['date_returned' => now()] : []));
            $mechanism->recordEvent('transmitted', $by, $from, $to, $note);

            return $mechanism;
        });

        if ($forCompliance) {
            $this->tell(
                $mechanism,
                'For compliance',
                'Your HR mechanism needs corrections before PSED can approve it.',
                [(string) $mechanism->remarks, 'The signed transmittal, the reviewed HR mechanism and the HR checklist are attached. Correct the HR mechanism and upload it again from your tracking page.'],
                true,
            );
        } else {
            $this->tell(
                $mechanism,
                'Your HR mechanism was reviewed',
                'Your HR mechanism was reviewed and approved by the PSED Chief.',
                [
                    'Print three (3) copies of the HR Mechanism for the signature of the Head of Agency, then submit the signed copies to the CSC Regional Office VIII for final approval.',
                    'The reviewed HR mechanism, the signed transmittal and the HR checklist are attached.',
                ],
                true,
            );
        }

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

    /** The Action Officer sent one approved copy to the Field Office and one to the agency; the third stays in the records of PSED. */
    public function distribute(HrMechanism $mechanism, User $by, ?string $note): HrMechanism
    {
        DB::transaction(function () use ($mechanism, $by, $note) {
            $mechanism->update(['date_distributed' => now()]);
            $mechanism->recordEvent('distributed', $by, $mechanism->status, $mechanism->status, $note);
        });

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
     * The checklist the Chief signed with their DICT PNPKI certificate replaces the one the Action Officer forwarded. The
     * earlier file is kept in the history of the mechanism, but the signed one is the checklist from now on.
     *
     * @param  array<string, mixed>  $signature
     */
    private function replaceChecklist(HrMechanism $mechanism, User $by, UploadedFile $file, array $signature): void
    {
        $mechanism->attachments()->create([
            'kind' => 'checklist',
            'path' => $file->store('hr-mechanisms/'.$mechanism->agency_id.'/'.$mechanism->id, 'local'),
            'name' => $file->getClientOriginalName(),
            'uploaded_by' => $by->id,
            'signed_by' => $signature['signer'],
            'signer_issuer' => $signature['issuer'],
            'signed_checked_at' => now(),
        ]);

        $mechanism->recordEvent('checklist_signed', $by, null, null, 'The HR checklist was signed with a PNPKI certificate by '.$signature['signer'].' (issued by '.$signature['issuer'].') and replaces the checklist the Action Officer forwarded.');
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
