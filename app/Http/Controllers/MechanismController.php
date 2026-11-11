<?php

namespace App\Http\Controllers;

use App\Enums\MechanismStatus;
use App\Enums\MechanismType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Models\HrMechanism;
use App\Models\HrMechanismAttachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MechanismController extends Controller
{
    /** The list: a Field Office sees its agencies, PSED and the Director everything. */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(MechanismStatus::class)],
            'type' => ['nullable', Rule::enum(MechanismType::class)],
            'field_office' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'mine' => ['nullable', 'boolean'],
            // A notification's link: the mechanism whose details open over the list.
            'open' => ['nullable', 'integer'],
        ]);

        $mechanisms = HrMechanism::visibleTo($user)->with('agency.fieldOffice', 'actionOfficer', 'officers')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('mechanism_type', $v))
            ->when($filters['mine'] ?? null, fn ($q) => $q->where(fn ($w) => $w
                ->where('action_officer_id', $user->id)
                ->orWhereHas('officers', fn ($o) => $o->where('action_officers.user_id', $user->id))))
            ->when($filters['field_office'] ?? null, fn ($q, $v) => $q->whereHas('agency', fn ($a) => $a->where('field_office_id', $v)))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w
                ->where('tracking_code', 'like', '%'.$v.'%')
                ->orWhere('title', 'like', '%'.$v.'%')
                ->orWhereHas('agency', fn ($a) => $a->where('name', 'like', '%'.$v.'%'))))
            ->latest('date_submitted')->latest('id')
            ->paginate(15)->withQueryString()
            ->through(fn (HrMechanism $m) => MechanismPresenter::row($m, $user));

        return Inertia::render('Mechanisms/Index', [
            'mechanisms' => $mechanisms,
            'filters' => $filters,
            'statuses' => collect(MechanismStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->value])->all(),
            'types' => collect(MechanismType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()])->all(),
            'fieldOffices' => $user->role === UserRole::FieldOffice ? [] : FieldOffice::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** What the modal shows: one mechanism, as JSON. */
    public function show(Request $request, HrMechanism $mechanism): JsonResponse
    {
        $this->authorize('view', $mechanism);

        return response()->json(MechanismPresenter::details($mechanism, $request->user()));
    }

    /** The file the agency uploaded: PSED and the Director only. The access is recorded. */
    public function file(Request $request, HrMechanism $mechanism): StreamedResponse
    {
        $this->authorize('download', $mechanism);
        abort_unless($mechanism->document_path && Storage::disk('local')->exists($mechanism->document_path), 404);

        AuditLog::record('mechanism.downloaded', $mechanism);

        return Storage::disk('local')->download($mechanism->document_path, $mechanism->document_name ?? basename($mechanism->document_path));
    }

    /** One of the three documents PSED attached: the reviewed mechanism, the transmittal or the checklist. */
    public function attachment(Request $request, HrMechanism $mechanism, string $kind): StreamedResponse
    {
        $this->authorize('download', $mechanism);
        abort_unless(array_key_exists($kind, HrMechanismAttachment::KINDS), 404);

        $file = $mechanism->currentAttachments()->firstWhere('kind', $kind);
        abort_unless($file && Storage::disk('local')->exists($file->path), 404);

        AuditLog::record('mechanism.downloaded', $mechanism, after: ['document' => $kind]);

        return Storage::disk('local')->download($file->path, $file->name);
    }
}
