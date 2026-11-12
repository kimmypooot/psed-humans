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
use App\Support\MechanismUndo;
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
            $mechanism->update(['status' => MechanismStatus::ReviewedByChief, 'date_evaluated' => now(), 'remarks' => null]);
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
            $mechanism->update(['status' => MechanismStatus::Processing, 'date_evaluated' => null, 'remarks' => $remarks]);
            $mechanism->recordEvent('returned_to_officer', $by, $from, MechanismStatus::Processing, $remarks);
        });

        StaffNotices::returnedToOfficer($mechanism, $by, $remarks);

        return $mechanism;
    }

    /**
     * The PSED Chief reviews what the Action Officer forwarded and agrees with it: the HR checklist they signed replaces the
     * officer's. The status is Reviewed. The Chief does not decide the outcome for the agency: the Action Officer chooses it
     * when generating the transmittal (Approved, or For Compliance), prints it for the Regional Director's signature, then
     * transmits it. The agency is not emailed yet.
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

        StaffNotices::readyToTransmit($mechanism, $by);

        return $mechanism;
    }

    /**
     * The Action Officer chooses what the transmittal says, now that the Chief reviewed it: Approved (`approval`: the status stays
     * Reviewed) or For Compliance (`compliance`: the status becomes For Compliance and `$corrections` says what the agency must
     * correct). The letter is made from what they filled in. It can be made again, even with the other type, until it is sent;
     * the latest one is the one to print.
     *
     * @param  array<string, mixed>  $transmittal  what the letter says; see Transmittal::rules
     */
    public function generateTransmittal(HrMechanism $mechanism, User $by, string $type, ?string $corrections, array $transmittal): HrMechanism
    {
        $compliance = $type === 'compliance';
        $to = $compliance ? MechanismStatus::ForCompliance : MechanismStatus::Reviewed;

        DB::transaction(function () use ($mechanism, $by, $type, $corrections, $transmittal, $compliance, $to) {
            $from = $mechanism->status;
            $this->writeTransmittal($mechanism, $by, $type, $transmittal);

            // Choosing For Compliance records what needs correcting; choosing Approved again drops it.
            $mechanism->update(['status' => $to] + ($compliance ? ['remarks' => $corrections, 'date_processed' => $mechanism->date_processed ?? now()] : ($from === MechanismStatus::ForCompliance ? ['remarks' => null] : [])));
            $mechanism->recordEvent('transmittal_generated', $by, $from, $to, 'The '.Transmittal::TYPES[$type].' transmittal was generated.'.($compliance ? ' To correct: '.$corrections : ''));
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

        $this->emailTransmittal($mechanism, $forCompliance);

        return $mechanism;
    }

    /**
     * Something in what the agency was sent was wrong (the wrong signed transmittal, or the wrong reviewed HR mechanism). The
     * Action Officer uploads the corrected signed transmittal, and the reviewed HR mechanism if it changed, with the reason, and
     * the agency is emailed again with the current documents and told to use these instead of the earlier ones. The status
     * does not change. The earlier files are kept, and the reason is kept in the history (the agency is not shown it).
     *
     * @throws RuntimeException when a document is missing
     */
    public function correct(HrMechanism $mechanism, User $by, UploadedFile $signedTransmittal, ?UploadedFile $reviewed, string $reason): HrMechanism
    {
        $this->requireDocuments($mechanism);
        $forCompliance = $mechanism->status === MechanismStatus::TransmittalCompliance;

        DB::transaction(function () use ($mechanism, $by, $signedTransmittal, $reviewed, $reason) {
            $mechanism->attachments()->create([
                'kind' => 'transmittal',
                'path' => $signedTransmittal->store('hr-mechanisms/'.$mechanism->agency_id.'/'.$mechanism->id, 'local'),
                'name' => $signedTransmittal->getClientOriginalName(),
                'uploaded_by' => $by->id,
            ]);
            $this->attach($mechanism, $by, ['reviewed' => $reviewed]);

            $mechanism->update(['date_transmitted' => now()]);
            $mechanism->recordEvent('retransmitted', $by, $mechanism->status, $mechanism->status, $reason);
        });

        $this->emailTransmittal($mechanism, $forCompliance, corrected: true);

        return $mechanism;
    }

    /** Emails the agency what the Chief decided, with the three documents. */
    private function emailTransmittal(HrMechanism $mechanism, bool $forCompliance, bool $corrected = false): void
    {
        $replaces = $corrected ? ['These documents replace the ones PSED sent you earlier. Please use these and disregard the earlier ones.'] : [];

        if ($forCompliance) {
            $this->tell(
                $mechanism,
                $corrected ? 'For compliance (corrected documents)' : 'For compliance',
                'Your HR mechanism needs corrections before PSED can approve it.',
                [...$replaces, (string) $mechanism->remarks, 'The signed transmittal, the reviewed HR mechanism and the HR checklist are attached. Correct the HR mechanism and upload it again from your tracking page.'],
                true,
            );
        } else {
            $this->tell(
                $mechanism,
                $corrected ? 'Your HR mechanism was reviewed (corrected documents)' : 'Your HR mechanism was reviewed',
                'Your HR mechanism was reviewed and approved by the PSED Chief.',
                [
                    ...$replaces,
                    'Print three (3) copies of the HR Mechanism for the signature of the Head of Agency, then submit the signed copies to the CSC Regional Office VIII for final approval.',
                    'The reviewed HR mechanism, the signed transmittal and the HR checklist are attached.',
                ],
                true,
            );
        }
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

    /**
     * Takes back the most recent step (see MechanismUndo). The step stays in the history and an "undone" entry with the reason
     * is added after it. The checklist the Chief signed is kept but is no longer the current one when their decision is undone.
     *
     * @throws RuntimeException when there is nothing to undo
     */
    public function undo(HrMechanism $mechanism, User $by, string $reason): HrMechanism
    {
        $step = MechanismUndo::step($mechanism);

        if ($step === null) {
            throw new RuntimeException('There is nothing to undo.');
        }

        $from = $mechanism->status;

        DB::transaction(function () use ($mechanism, $by, $reason, $step, $from) {
            match ($step['action']) {
                'routed' => $this->undoRouting($mechanism),
                'acknowledged' => $mechanism->update(['status' => MechanismStatus::Routed, 'acknowledged_at' => null]),
                'forwarded' => $mechanism->update(['status' => MechanismStatus::Processing, 'date_evaluated' => null]),
                default => $this->undoDecision($mechanism),
            };

            $mechanism->recordEvent('undone', $by, $from, $mechanism->status, $step['label'].'. '.$reason);
        });

        StaffNotices::undone($mechanism, $by, $step['label'], $reason);

        return $mechanism;
    }

    /** Back to Received, as if it was not routed: no officer and no clock. */
    private function undoRouting(HrMechanism $mechanism): void
    {
        $mechanism->update([
            'status' => MechanismStatus::Received,
            'action_officer_id' => null,
            'processor' => null,
            'routed_at' => null,
            'acknowledged_at' => null,
            'due_at' => null,
            'date_processed' => null,
        ]);
        $mechanism->officers()->sync([]);
    }

    /** Back to waiting for the Chief: the checklist they signed is set aside and the officer's checklist is the current one again. */
    private function undoDecision(HrMechanism $mechanism): void
    {
        $signed = $mechanism->attachments()->where('kind', 'checklist')->whereNotNull('signed_by')->whereNull('reverted_at')->latest('id')->first();
        $signed?->update(['reverted_at' => now()]);

        $mechanism->update(['status' => MechanismStatus::ReviewedByChief, 'date_reviewed' => null, 'remarks' => null]);
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
