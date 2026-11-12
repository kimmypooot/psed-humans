<?php

namespace App\Http\Controllers;

use App\Models\HrMechanism;
use App\Models\HrMechanismAttachment;
use App\Models\User;
use App\Support\MechanismTimeline;
use App\Support\MechanismUndo;
use Illuminate\Support\Facades\Gate;

/** Turns a mechanism into the plain array the pages render, with what the viewer may do with it. */
class MechanismPresenter
{
    /** "MSP", "Revised MSP", or "PRAISE: Revised R&R": the kind, with the name the agency gave only when it adds something. */
    public static function label(HrMechanism $m): string
    {
        $type = $m->mechanism_type->value;
        $title = trim((string) $m->title);

        // "ADR on SHC" already says what ADRSHC says.
        $says = mb_stripos($title, $type) !== false || ($type === 'ADRSHC' && mb_stripos($title, 'ADR') !== false);

        if ($title === '' || $says) {
            return $title !== '' ? $title : $type;
        }

        return $type.': '.$title;
    }

    /** @return array<string, mixed> */
    public static function row(HrMechanism $m, User $user): array
    {
        $gate = Gate::forUser($user);

        return [
            'id' => $m->id,
            'tracking_code' => $m->tracking_code,
            'agency' => $m->agency?->name,
            'field_office' => $m->agency?->fieldOffice?->name,
            'type' => $m->mechanism_type->value,
            'type_label' => $m->mechanism_type->label(),
            'label' => self::label($m),
            'status' => $m->status->value,
            'tone' => $m->status->tone(),
            'date_submitted' => $m->date_submitted?->toIso8601String(),
            'date_returned' => $m->date_returned?->toIso8601String(),
            'date_approved' => $m->date_approved?->toIso8601String(),
            'remarks' => $m->remarks,
            // Every officer by initials, with the name where known. Old mechanisms can have two.
            'officers' => $m->officers->map(fn ($o) => ['id' => $o->id, 'initials' => $o->initials, 'name' => $o->name])->values()->all(),
            'officer' => $m->actionOfficer?->name ?? $m->officers->map(fn ($o) => $o->initials)->implode(' & ') ?: null,
            'is_mine' => $m->action_officer_id !== null && $m->action_officer_id === $user->id
                || $m->officers->contains(fn ($o) => $o->user_id === $user->id),
            'turnaround' => MechanismTimeline::turnaround($m),
            'undo_label' => $gate->allows('undo', $m) ? MechanismUndo::step($m)['label'] : null,
            'can' => [
                'undo' => $gate->allows('undo', $m),
                'route' => $gate->allows('route', $m),
                'acknowledge' => $gate->allows('acknowledge', $m),
                'work' => $gate->allows('work', $m),
                'review' => $gate->allows('review', $m),
                'returnToOfficer' => $gate->allows('returnToOfficer', $m),
                'generateTransmittal' => $gate->allows('generateTransmittal', $m),
                'correct' => $gate->allows('correct', $m),
                'transmit' => $gate->allows('transmit', $m) && $m->hasGeneratedTransmittal(),
                'finalApprove' => $gate->allows('finalApprove', $m),
                'distribute' => $gate->allows('distribute', $m),
            ],
        ];
    }

    /**
     * The three documents PSED sends back, each with its file name and a link to open it when this person may.
     *
     * @return list<array{kind: string, label: string, name: ?string, url: ?string}>
     */
    public static function attachments(HrMechanism $m, User $user): array
    {
        $canOpen = Gate::forUser($user)->allows('download', $m);
        $current = $m->currentAttachments();

        return collect(HrMechanismAttachment::KINDS)->map(function (string $label, string $kind) use ($m, $canOpen, $current) {
            $file = $current->firstWhere('kind', $kind);

            return [
                'kind' => $kind,
                'label' => $label,
                'name' => $file?->name,
                'url' => $file && $canOpen ? "/mechanisms/{$m->id}/attachments/{$kind}" : null,
                // The PSED Chief's DICT PNPKI signature on the HR checklist, once they have signed it.
                'signed_by' => $file?->signed_by,
                'signer_issuer' => $file?->signer_issuer,
            ];
        })->values()->all();
    }

    /** Everything the modal shows: the row, the details, the stepper and the history (with names, for staff). */
    public static function details(HrMechanism $m, User $user): array
    {
        $m->loadMissing('agency.fieldOffice', 'actionOfficer', 'officers');
        $canOpen = Gate::forUser($user)->allows('download', $m);

        return [
            ...self::row($m, $user),
            'title' => $m->title,
            'copies' => $m->copies,
            'processor' => $m->processor,
            'location' => $m->location,
            'receipt_details' => $m->receipt_details,
            'document_name' => $m->document_name,
            'has_document' => $m->document_path !== null,
            'can_download' => $canOpen,
            'contact_email' => $m->contact_email,
            'contact_name' => $m->contact_name,
            'is_legacy' => $m->is_legacy,
            'can_name_officers' => $user->isPsed(),
            'action_officer_id' => $m->action_officer_id,
            'attachments' => self::attachments($m, $user),
            'dates' => [
                ['label' => 'Received by PSED', 'value' => $m->date_submitted?->toIso8601String()],
                ['label' => 'Routed to Action Officer', 'value' => $m->routed_at?->toIso8601String()],
                ['label' => 'Receipt acknowledged', 'value' => $m->acknowledged_at?->toIso8601String()],
                ['label' => 'Target date (20 working days)', 'value' => $m->due_at?->toIso8601String()],
                ['label' => 'Forwarded to PSED Chief', 'value' => $m->date_evaluated?->toIso8601String()],
                ['label' => 'Reviewed by PSED Chief', 'value' => $m->date_reviewed?->toIso8601String()],
                ['label' => 'For compliance', 'value' => $m->date_returned?->toIso8601String()],
                ['label' => 'Signed copies received', 'value' => $m->date_submitted_to_rd?->toIso8601String()],
                ['label' => 'Approved', 'value' => $m->date_approved?->toIso8601String()],
            ],
            'steps' => MechanismTimeline::steps($m, true),
            'history' => MechanismTimeline::history($m, false),
        ];
    }
}
