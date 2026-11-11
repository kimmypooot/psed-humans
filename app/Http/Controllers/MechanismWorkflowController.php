<?php

namespace App\Http\Controllers;

use App\Actions\HrMechanisms\ProcessHrMechanism;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\HrMechanism;
use App\Models\HrMechanismAttachment;
use App\Models\User;
use App\Support\Transmittal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    /** The Action Officer acknowledges receipt: the 20-day clock starts. */
    public function acknowledge(Request $request, HrMechanism $mechanism): RedirectResponse
    {
        $this->authorize('acknowledge', $mechanism);

        $this->process->acknowledge($mechanism, $request->user());
        AuditLog::record('mechanism.acknowledged', $mechanism);

        return back()->with('status', 'Receipt acknowledged. The '.config('hrmm.turnaround_days').'-working-day turnaround started; the target date is '.$mechanism->due_at->format('M j, Y').'.');
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

    /**
     * For Compliance: the Chief's decision after reviewing. Nothing is uploaded: the reviewed HR mechanism and the
     * HR checklist are the ones the Action Officer forwarded. The For Compliance transmittal is made from what the
     * Chief filled in, and the agency gets it by email with those two documents.
     */
    public function returnForCompliance(Request $request, HrMechanism $mechanism): RedirectResponse
    {
        $this->authorize('returnForCompliance', $mechanism);

        $data = $request->validate(['remarks' => ['required', 'string', 'min:5', 'max:2000'], 'transmittal' => ['required', 'array']] + Transmittal::rules('transmittal.'));
        $letter = $this->letter($data['transmittal']);
        $this->guard(fn () => $this->process->returnForCompliance($mechanism, $request->user(), $data['remarks'], $letter));
        AuditLog::record('mechanism.returned', $mechanism, after: ['remarks' => $data['remarks']]);

        return back()->with('status', 'Marked For Compliance. The transmittal, the reviewed HR mechanism and the checklist were emailed to the agency.');
    }

    /** The Chief's decision when it is for approval: the For Approval transmittal is made, and the agency is emailed it with the two documents. */
    public function chiefApprove(Request $request, HrMechanism $mechanism): RedirectResponse
    {
        $this->authorize('review', $mechanism);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000'], 'transmittal' => ['required', 'array']] + Transmittal::rules('transmittal.'));
        $letter = $this->letter($data['transmittal']);
        $this->guard(fn () => $this->process->chiefApprove($mechanism, $request->user(), $data['note'] ?? null, $letter));
        AuditLog::record('mechanism.reviewed', $mechanism);

        return back()->with('status', 'Approved. The agency was emailed the transmittal, the reviewed HR mechanism and the HR checklist.');
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
