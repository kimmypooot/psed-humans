<?php

namespace App\Support;

use App\Enums\MechanismStatus;
use App\Models\HrMechanism;

/**
 * The stepper and the history of a mechanism, the same for the public tracking page
 * and for the modal PSED opens. Dates are ISO strings; states are done, current,
 * returned (a stop that needs the agency) or upcoming.
 *
 * The five stops: Received, Routed, Evaluating (the 20-day clock), PSED Chief review, Approved.
 */
class MechanismTimeline
{
    /**
     * With `$internal`, the Action Officer is named; on the public page they are not.
     *
     * @return list<array{key: string, label: string, hint: string, state: string, date: ?string}>
     */
    public static function steps(HrMechanism $m, bool $internal = false): array
    {
        $s = $m->status;
        $officer = $internal && $m->actionOfficer ? ' to '.$m->actionOfficer->name : '';
        $due = $m->due_at ? ' Target date: '.$m->due_at->format('M j, Y').'.' : '';

        $routed = match ($s) {
            MechanismStatus::Received => ['current', 'Waiting for PSED to route it to an Action Officer.', null],
            default => ['done', 'Routed'.($officer ?: ' to an Action Officer').'.', $m->routed_at],
        };

        $evaluating = match ($s) {
            MechanismStatus::Received => ['upcoming', 'The Action Officer evaluates it within '.Turnaround::days().' working days.', null],
            MechanismStatus::Routed => ['current', 'Waiting for the Action Officer to acknowledge receipt. The '.Turnaround::days().'-day clock starts then.', null],
            MechanismStatus::Processing => ['current', 'Being processed and evaluated.'.$due, $m->acknowledged_at],
            MechanismStatus::ForCompliance => ['returned', 'Sent back to the agency for compliance. Correct it and upload it again.', $m->date_returned],
            default => ['done', 'Evaluated by the Action Officer.', $m->date_evaluated ?? $m->acknowledged_at],
        };

        $chief = match ($s) {
            MechanismStatus::ForChiefReview => ['current', 'Waiting for the PSED Chief to review it.', $m->date_evaluated],
            MechanismStatus::ReviewedByChief, MechanismStatus::Approved => ['done', 'Reviewed and approved by the PSED Chief.', $m->date_reviewed],
            default => ['upcoming', 'The PSED Chief reviews what the Action Officer forwards.', null],
        };

        $approval = match ($s) {
            MechanismStatus::ReviewedByChief => ['current', 'Print three (3) copies for the signature of the Head of Agency and submit them to the Regional Office.', $m->date_reviewed],
            MechanismStatus::Approved => ['done', 'Approved by the Regional Office.', $m->date_approved],
            default => ['upcoming', 'After the Chief, the signed copies are submitted for final approval.', null],
        };

        // Rows from the old workbook never had routing or an acknowledgment email: say only what is known.
        $receivedHint = $m->is_legacy ? 'Received by PSED.' : 'Received. An acknowledgment was emailed to the agency.';
        if ($m->is_legacy && $routed[0] === 'done') {
            $routed[1] = 'Handled by PSED.';
        }

        return [
            ['key' => 'received', 'label' => 'Received by PSED', 'hint' => $receivedHint, 'state' => 'done', 'date' => $m->date_submitted?->toIso8601String()],
            ['key' => 'routed', 'label' => 'Routed', 'hint' => $routed[1], 'state' => $routed[0], 'date' => $routed[2]?->toIso8601String()],
            ['key' => 'evaluating', 'label' => 'Evaluating', 'hint' => $evaluating[1], 'state' => $evaluating[0], 'date' => $evaluating[2]?->toIso8601String()],
            ['key' => 'chief', 'label' => 'PSED Chief review', 'hint' => $chief[1], 'state' => $chief[0], 'date' => $chief[2]?->toIso8601String()],
            ['key' => 'approved', 'label' => 'Signature and approval', 'hint' => $approval[1], 'state' => $approval[0], 'date' => $approval[2]?->toIso8601String()],
        ];
    }

    /**
     * What happened, oldest first. With `$public`, people are not named and the internal steps
     * (who it was routed to, the officer's note, a failed email) are left out.
     *
     * @return list<array{id: int, action: string, title: string, by: ?string, remarks: ?string, at: ?string}>
     */
    public static function history(HrMechanism $m, bool $public): array
    {
        $titles = [
            'submitted' => 'Received by PSED',
            'resubmitted' => 'Corrected file received',
            'routed' => 'Routed to Action Officer',
            'acknowledged' => 'Action Officer acknowledged receipt',
            'forwarded' => 'Forwarded to the PSED Chief',
            'returned' => 'For compliance',
            'reviewed' => 'Reviewed by PSED Chief',
            'approved' => 'Approved',
            'email_failed' => 'Email not sent',
        ];

        return $m->events()->with('user:id,name')->get()
            ->reject(fn ($e) => $public && $e->action === 'email_failed')
            ->map(fn ($e) => [
                'id' => $e->id,
                'action' => $e->action,
                'title' => $titles[$e->action] ?? $e->action,
                'by' => $public
                    ? match ($e->action) {
                        'submitted', 'resubmitted' => 'Agency',
                        'approved' => 'Regional Office',
                        default => 'PSED',
                    }
                    : ($e->user?->name ?? ($e->user_id === null && in_array($e->action, ['submitted', 'resubmitted'], true) ? 'Agency' : null)),
                'remarks' => $public && in_array($e->action, ['routed', 'forwarded'], true) ? null : $e->remarks,
                'at' => $e->created_at?->toIso8601String(),
            ])->values()->all();
    }

    /**
     * The state of the 20-day clock, or null when it has not started.
     *
     * @return array{started: string, due: string, days_left: int, overdue: bool, running: bool}|null
     */
    public static function turnaround(HrMechanism $m): ?array
    {
        if ($m->acknowledged_at === null || $m->due_at === null) {
            return null;
        }

        $left = Turnaround::daysLeft($m->due_at);

        return [
            'started' => $m->acknowledged_at->toIso8601String(),
            'due' => $m->due_at->toIso8601String(),
            'days_left' => $left,
            'overdue' => $left < 0 && $m->status->clockRuns(),
            'running' => $m->status->clockRuns(),
        ];
    }
}
