<?php

namespace App\Http\Controllers\Public;

use App\Actions\HrMechanisms\SubmitHrMechanism;
use App\Enums\MechanismStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\MechanismPresenter;
use App\Models\AuditLog;
use App\Models\HrMechanism;
use App\Notifications\MechanismStatusChanged;
use App\Support\MechanismTimeline;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where an agency follows its submission. The credential is a signed link (sent by email, or
 * issued here when the tracking code and the email address match). The page shows the stepper,
 * the history and, when PSED returned the mechanism, the form to upload the corrected file.
 */
class TrackingController extends Controller
{
    /** Ask for the tracking code and the email address left on the submission. */
    public function lookup(): Response
    {
        return Inertia::render('Public/Track');
    }

    public function find(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'tracking_code' => ['required', 'string', 'max:40'],
            'contact_email' => ['required', 'email:rfc', 'max:255'],
        ]);

        $mechanism = HrMechanism::where('tracking_code', strtoupper(trim($data['tracking_code'])))
            ->where('contact_email', mb_strtolower(trim($data['contact_email'])))
            ->first();

        // The same answer whether the code or the address was wrong: neither is given away.
        if ($mechanism === null) {
            return back()->withErrors(['tracking_code' => 'We could not find a submission with that tracking code and email address.'])->onlyInput('tracking_code', 'contact_email');
        }

        return redirect(MechanismStatusChanged::trackingUrl($mechanism));
    }

    public function show(HrMechanism $mechanism): Response
    {
        $mechanism->load('agency.fieldOffice');
        $returned = $mechanism->status === MechanismStatus::TransmittalCompliance;

        return Inertia::render('Public/Status', [
            'mechanism' => [
                'tracking_code' => $mechanism->tracking_code,
                'agency' => $mechanism->agency->name,
                'label' => MechanismPresenter::label($mechanism),
                'type_label' => $mechanism->mechanism_type->label(),
                'status' => $mechanism->status->value,
                'tone' => $mechanism->status->tone(),
                // Only what the agency must correct; what the Chief told the Action Officer stays inside PSED.
                'remarks' => $mechanism->status === MechanismStatus::TransmittalCompliance ? $mechanism->remarks : null,
                'date_submitted' => $mechanism->date_submitted?->toIso8601String(),
                'updated_at' => $mechanism->updated_at?->toIso8601String(),
                'steps' => MechanismTimeline::steps($mechanism),
                'history' => MechanismTimeline::history($mechanism, true),
            ],
            // Only a returned mechanism can be answered, and only through its own signed link.
            'resubmitUrl' => $returned ? URL::signedRoute('track.resubmit', $mechanism) : null,
            'maxMb' => (int) floor(config('hrmm.max_upload_kb') / 1024),
        ]);
    }

    public function resubmit(Request $request, HrMechanism $mechanism, SubmitHrMechanism $submit): RedirectResponse
    {
        abort_unless($mechanism->status === MechanismStatus::TransmittalCompliance, 403, 'This HR mechanism is not waiting for a corrected file.');

        $data = $request->validate([
            'document' => ['required', 'file', 'extensions:docx', 'mimes:docx', 'max:'.config('hrmm.max_upload_kb')],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'document.required' => 'Attach the corrected file.',
            'document.mimes' => 'The file must be a Word (.docx) file.',
            'document.extensions' => 'The file must be a Word (.docx) file.',
        ]);

        $submit->resubmit($mechanism, $request->file('document'), $data['note'] ?? null);
        AuditLog::record('mechanism.resubmitted', $mechanism, after: ['public' => true]);

        return redirect(MechanismStatusChanged::trackingUrl($mechanism))->with('status', 'Submitted again. PSED will review it.');
    }
}
