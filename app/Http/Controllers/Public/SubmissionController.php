<?php

namespace App\Http\Controllers\Public;

use App\Actions\HrMechanisms\SubmitHrMechanism;
use App\Enums\MechanismType;
use App\Http\Controllers\Controller;
use App\Http\Requests\PublicSubmissionRequest;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Notifications\MechanismStatusChanged;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** The public page where an agency submits an HR mechanism. No account: an email address is all it leaves. */
class SubmissionController extends Controller
{
    public function create(): Response
    {
        // Agencies grouped by the Field Office that oversees them, the way psed-app lists them.
        $groups = FieldOffice::orderBy('name')->get()->map(fn (FieldOffice $office) => [
            'label' => $office->name,
            'options' => Agency::where('listed', true)->where('field_office_id', $office->id)->orderBy('name')->get(['id', 'name'])
                ->map(fn (Agency $a) => ['value' => (string) $a->id, 'label' => $a->name])->all(),
        ])->filter(fn (array $g) => $g['options'] !== [])->values();

        $unplaced = Agency::where('listed', true)->whereNull('field_office_id')->orderBy('name')->get(['id', 'name'])
            ->map(fn (Agency $a) => ['value' => (string) $a->id, 'label' => $a->name])->all();

        if ($unplaced !== []) {
            $groups->push(['label' => 'Other agencies', 'options' => $unplaced]);
        }

        return Inertia::render('Public/Submit', [
            'agencies' => $groups->all(),
            'types' => collect(MechanismType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()])->all(),
            'maxMb' => (int) floor(config('hrmm.max_upload_kb') / 1024),
        ]);
    }

    public function store(PublicSubmissionRequest $request, SubmitHrMechanism $submit): RedirectResponse
    {
        $mechanism = $submit(
            Agency::findOrFail($request->validated('agency_id')),
            MechanismType::from($request->validated('mechanism_type')),
            $request->validated('title'),
            $request->validated('contact_email'),
            $request->validated('contact_name'),
            $request->validated('note'),
            $request->file('document'),
        );

        AuditLog::record('mechanism.submitted', $mechanism, after: ['tracking_code' => $mechanism->tracking_code, 'public' => true]);

        return redirect(MechanismStatusChanged::trackingUrl($mechanism))
            ->with('submitted', true)
            ->with('status', 'Submitted. We emailed a link to '.$mechanism->contact_email.'.');
    }
}
