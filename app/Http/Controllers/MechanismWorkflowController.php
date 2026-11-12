<?php

namespace App\Http\Controllers;

use App\Actions\HrMechanisms\ProcessHrMechanism;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\HrMechanism;
use App\Models\HrMechanismAttachment;
use App\Models\User;
use App\Support\MechanismUndo;
use App\Support\PdfSignature;
use App\Support\Transmittal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** PSED and the Director move a mechanism along. Each step checks the policy for the status it needs. */
class MechanismWorkflowController extends Controller
{
    private const FILE_RULES = ['nullable', 'file', 'mimes:pdf,doc,docx,xls,xlsx,zip', 'max:20480'];

    public function __construct(private ProcessHrMechanism $process) {}

    /** PSED staff route a submission to an Action Officer: someone else, or themselves. */
    public function route(Request $request, HrMechanism $mechanism): RedirectResponse
    {
        $this->authorize('route', $mechanism);

        $data = $request->validate([
            'officer_id' => ['required', 'integer', Rule::exists('users', 'id')->whereIn('role', [UserRole::PsedStaff->value, UserRole::PsedChief->value])],
            'note' => ['nullable', 'string', 'max:2000'],
        ], ['officer_id.required' => 'Choose the Action Officer.']);

        $officer = User::findOrFail($data['officer_id']);
        $this->process->route($mechanism, $request->user(), $officer, $data['note'] ?? null);
        AuditLog::record('mechanism.routed', $mechanism, after: ['officer_id' => $officer->id]);

        return back()->with('status', 'Routed to '.$officer->name.($officer->is($request->user()) ? ' (you)' : '').'.');
    }

    /** The Action Officer acknowledges receipt: the 20-day clock has been running since it was routed. */
    public function acknowledge(Request $request, HrMechanism $mechanism): RedirectResponse
    {
        $this->authorize('acknowledge', $mechanism);

        $this->process->acknowledge($mechanism, $request->user());
        AuditLog::record('mechanism.acknowledged', $mechanism);

        return back()->with('status', 'Receipt acknowledged. The target date of the '.config('hrmm.turnaround_days').'-working-day turnaround, counted from routing, is '.$mechanism->due_at->format('M j, Y').'.');
    }

    /** The Action Officer forwards the evaluated mechanism, with the reviewed HR mechanism and the HR checklist, to the PSED Chief. */
    public function forward(Request $request, HrMechanism $mechanism): RedirectResponse
    {
        $this->authorize('work', $mechanism);

        $data = $request->validate($this->documentRules() + ['note' => ['nullable', 'string', 'max:2000']]);
        $this->attach($request, $mechanism);
        $this->guard(fn () => $this->process->forward($mechanism, $request->user(), $data['note'] ?? null));
        AuditLog::record('mechanism.forwarded', $mechanism);

        return back()->with('status', 'Forwarded to the PSED Chief.');
    }

    /** Not approved: the Chief sends it back to the Action Officer to evaluate again. */
    public function returnToOfficer(Request $request, HrMechanism $mechanism): RedirectResponse
    {
        $this->authorize('returnToOfficer', $mechanism);

        $data = $request->validate(['remarks' => ['required', 'string', 'min:5', 'max:2000']], ['remarks.required' => 'Say what the Action Officer should look at again.']);
        $this->process->returnToOfficer($mechanism, $request->user(), $data['remarks']);
        AuditLog::record('mechanism.returned_to_officer', $mechanism, after: ['remarks' => $data['remarks']]);

        return back()->with('status', 'Returned to the Action Officer for re-evaluation.');
    }

    /** Takes back the most recent step, with the reason. Nothing is deleted: the step and the undo stay in the history. */
    public function undo(Request $request, HrMechanism $mechanism): RedirectResponse
    {
        $this->authorize('undo', $mechanism);

        $data = $request->validate(['remarks' => ['required', 'string', 'min:5', 'max:2000']], ['remarks.required' => 'Say why this is being undone.', 'remarks.min' => 'Say why this is being undone.']);
        $label = MechanismUndo::step($mechanism)['label'];
        $this->guard(fn () => $this->process->undo($mechanism, $request->user(), $data['remarks']));
        AuditLog::record('mechanism.undone', $mechanism, after: ['step' => $label, 'status' => $mechanism->status->value, 'reason' => $data['remarks']]);

        return back()->with('status', $label.': it is now '.$mechanism->status->value.'.');
    }

    /**
     * The Action Officer chooses the outcome (Approved, or For Compliance with what the agency must correct) and makes the transmittal
     * letter, so it can be printed for the Regional Director's signature.
     */
    public function generateTransmittal(Request $request, HrMechanism $mechanism): RedirectResponse
    {
        $this->authorize('generateTransmittal', $mechanism);

        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Transmittal::TYPES))],
            'remarks' => ['nullable', 'required_if:type,compliance', 'string', 'min:5', 'max:2000'],
            'transmittal' => ['required', 'array'],
        ] + Transmittal::rules('transmittal.'), [
            'type.required' => 'Choose the transmittal: Approved or For Compliance.',
            'remarks.required_if' => 'Say what the agency needs to correct.',
            'remarks.min' => 'Say what the agency needs to correct.',
        ]);
        $this->process->generateTransmittal($mechanism, $request->user(), $data['type'], $data['remarks'] ?? null, $this->letter($data['transmittal']));
        AuditLog::record('mechanism.transmittal_generated', $mechanism, after: ['type' => $data['type'], 'status' => $mechanism->status->value]);

        return back()->with('status', 'The '.Transmittal::TYPES[$data['type']].' transmittal was generated. Open it under Documents, print it for the Regional Director\'s signature, then upload the signed copy to email the agency.');
    }

    /**
     * The Regional Director signed the printed transmittal: the Action Officer uploads the signed copy and the agency is
     * emailed it with the reviewed HR mechanism and the HR checklist. The status becomes Transmittal (Approved) or
     * Transmittal (For Compliance), by what the Chief decided.
     */
    public function transmit(Request $request, HrMechanism $mechanism): RedirectResponse
    {
        $this->authorize('transmit', $mechanism);

        $data = $request->validate([
            'signed_transmittal' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:20480'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'signed_transmittal.required' => 'Upload the transmittal signed by the Regional Director.',
            'signed_transmittal.mimes' => 'The signed transmittal must be a PDF, an image or a Word file.',
        ]);
        $this->guard(fn () => $this->process->transmit($mechanism, $request->user(), $request->file('signed_transmittal'), $data['note'] ?? null));
        AuditLog::record('mechanism.transmitted', $mechanism, after: ['status' => $mechanism->status->value]);

        return back()->with('status', 'Recorded as '.$mechanism->status->value.'. The agency was emailed the signed transmittal, the reviewed HR mechanism and the HR checklist.');
    }

    /** Something sent to the agency was wrong: the Action Officer sends the corrected documents, with the reason. The status does not change. */
    public function correct(Request $request, HrMechanism $mechanism): RedirectResponse
    {
        $this->authorize('correct', $mechanism);

        $data = $request->validate([
            'signed_transmittal' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:20480'],
            'reviewed' => self::FILE_RULES,
            'remarks' => ['required', 'string', 'min:5', 'max:2000'],
        ], [
            'signed_transmittal.required' => 'Upload the corrected transmittal, signed by the Regional Director.',
            'signed_transmittal.mimes' => 'The signed transmittal must be a PDF, an image or a Word file.',
            'remarks.required' => 'Say what was wrong and what was corrected.',
            'remarks.min' => 'Say what was wrong and what was corrected.',
        ]);
        $this->guard(fn () => $this->process->correct($mechanism, $request->user(), $request->file('signed_transmittal'), $request->file('reviewed'), $data['remarks']));
        AuditLog::record('mechanism.corrected', $mechanism, after: ['reason' => $data['remarks']]);

        return back()->with('status', 'The corrected documents were emailed to the agency. The status is still '.$mechanism->status->value.'.');
    }

    /** The Action Officer sent the approved copies to the Field Office and the agency. */
    public function distribute(Request $request, HrMechanism $mechanism): RedirectResponse
    {
        $this->authorize('distribute', $mechanism);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $this->process->distribute($mechanism, $request->user(), $data['note'] ?? null);
        AuditLog::record('mechanism.distributed', $mechanism);

        return back()->with('status', 'Recorded: one copy to the Field Office, one to the agency, and one kept in the records of PSED.');
    }

    /** The Chief's decision when it is for approval, with the signed HR checklist. The Action Officer generates the transmittal next. The agency is not emailed yet. */
    public function chiefApprove(Request $request, HrMechanism $mechanism): RedirectResponse
    {
        $this->authorize('review', $mechanism);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        [$signed, $signature] = $this->signedChecklist($request);
        $this->guard(fn () => $this->process->chiefApprove($mechanism, $request->user(), $data['note'] ?? null, $signed, $signature));
        AuditLog::record('mechanism.reviewed', $mechanism);

        return back()->with('status', 'Reviewed. The Action Officer will generate the transmittal, have the Regional Director sign it, and send it to the agency.');
    }

    /** The signed copies are in: the Director IV (or PSED for them) gives the final approval. */
    public function finalApprove(Request $request, HrMechanism $mechanism): RedirectResponse
    {
        $this->authorize('finalApprove', $mechanism);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $this->process->finalApprove($mechanism, $request->user(), $data['note'] ?? null);
        AuditLog::record('mechanism.approved', $mechanism);

        return back()->with('status', 'Final approval recorded.');
    }

    /** Only the documents a person uploads; the transmittal is made by the system. @return array<string, array<int, mixed>> */
    private function documentRules(): array
    {
        return array_fill_keys(HrMechanismAttachment::UPLOADED, self::FILE_RULES);
    }

    /**
     * The HR checklist the Chief signed with their DICT PNPKI certificate, checked: a PDF carrying a digital signature that
     * is still valid (the file was not changed after signing), issued by DICT's PNPKI, by the Chief themselves.
     *
     * @return array{0: UploadedFile, 1: array<string, mixed>}
     *
     * @throws ValidationException
     */
    private function signedChecklist(Request $request): array
    {
        $request->validate(['signed_checklist' => ['required', 'file', 'mimes:pdf', 'max:20480']], [
            'signed_checklist.required' => 'Upload the HR checklist signed with your DICT PNPKI certificate.',
            'signed_checklist.mimes' => 'The signed HR checklist must be a PDF.',
        ]);

        $file = $request->file('signed_checklist');
        $signature = PdfSignature::inspect($file->getRealPath(), config('hrmm.pnpki_ca_bundle'));
        $user = $request->user();
        $fail = fn (string $message) => throw ValidationException::withMessages(['signed_checklist' => $message]);

        $signer = (string) $signature['signer'];
        $issuer = (string) $signature['issuer'];

        match (true) {
            ! $signature['signed'] => $fail('This PDF has no digital signature. Sign the HR checklist with your DICT PNPKI certificate, then upload the signed PDF.'),
            ! $signature['intact'] => $fail('The signature is not valid: the PDF was changed after it was signed, or the signature is damaged. Sign the HR checklist again.'),
            $signature['trusted'] === false => $fail('The certificate that signed this PDF is not trusted by the PNPKI certificate authorities.'),
            $signer === '' => $fail('The signing certificate could not be read from the PDF.'),
            preg_match((string) config('hrmm.pnpki_issuer'), $issuer) !== 1 => $fail("This PDF was signed with a certificate issued by \"{$issuer}\", not by DICT PNPKI. Sign the HR checklist with your DICT PNPKI certificate."),
            ! $this->isSigner($signer, $user->name) => $fail("This PDF was signed by \"{$signer}\". The HR checklist must be signed by you, {$user->name}, the PSED Chief."),
            default => null,
        };

        return [$file, $signature];
    }

    /** Whether the certificate's name is this person: every word of their name appears in it, ignoring case and accents. */
    private function isSigner(string $certificateName, string $userName): bool
    {
        $plain = fn (string $s) => mb_strtolower(Str::ascii($s));
        $name = $plain($certificateName);
        $words = array_filter(preg_split('/[^a-z0-9]+/', $plain($userName)) ?: [], fn ($w) => strlen($w) >= 2);

        return $words !== [] && collect($words)->every(fn ($w) => str_contains($name, $w));
    }

    /**
     * What the letter says: the form's fields, with the signatory's name and position taken from IMIS by the id picked.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function letter(array $fields): array
    {
        return TransmittalController::letter($fields);
    }

    /** Stores the documents sent with the request. */
    private function attach(Request $request, HrMechanism $mechanism): void
    {
        $this->process->attach($mechanism, $request->user(), $request->allFiles());
    }

    /** Turns a missing-document error into a message on the form. */
    private function guard(callable $step): void
    {
        try {
            $step();
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['reviewed' => $e->getMessage()]);
        }
    }
}
