<?php

namespace App\Support;

use App\Enums\MechanismStatus;
use App\Models\HrMechanism;

/**
 * Which step of a mechanism may be undone. Only the most recent step counts, and only while nothing has left PSED
 * and nobody has built on it: routing (before it was acknowledged), the Action Officer's acknowledgement, forwarding
 * to the PSED Chief (before the Chief decides) and the Chief's decision (before the Action Officer generates the
 * transmittal). Once the agency is emailed there is no undo. An undone step is not deleted: it stays in the history,
 * with an "undone" entry after it, and the undone entries are matched to the steps they undid so earlier steps can be undone in turn.
 */
class MechanismUndo
{
    /** The history entry (action) that can be undone, the status it left the mechanism in, and what the button says. */
    private const STEPS = [
        'routed' => [MechanismStatus::Routed, 'Undo routing'],
        'acknowledged' => [MechanismStatus::Processing, 'Undo acknowledgement'],
        'forwarded' => [MechanismStatus::ReviewedByChief, 'Withdraw from the Chief'],
        'reviewed' => [MechanismStatus::Reviewed, 'Undo approval'],
        'marked_for_compliance' => [MechanismStatus::ForCompliance, 'Undo For Compliance'],
    ];

    /** Entries that belong to another step and are never a step of their own. */
    private const SIDE_ENTRIES = ['checklist_signed', 'email_failed'];

    /** @return array{action: string, label: string}|null null when nothing can be undone now */
    public static function step(HrMechanism $m): ?array
    {
        if (! in_array($m->status, array_map(fn ($s) => $s[0], self::STEPS), true)) {
            return null;
        }

        $skip = 0;

        foreach ($m->events()->reorder()->orderByDesc('id')->get(['id', 'action', 'from_status']) as $event) {
            if (in_array($event->action, self::SIDE_ENTRIES, true)) {
                continue;
            }

            if ($event->action === 'undone') {
                $skip++;

                continue;
            }

            if ($skip > 0) {
                $skip--;

                continue;
            }

            $step = self::STEPS[$event->action] ?? null;

            if ($step === null || $step[0] !== $m->status) {
                return null;
            }

            // Routing again from Routed is not undone: route it to the right person instead.
            if ($event->action === 'routed' && $event->from_status !== MechanismStatus::Received->value) {
                return null;
            }

            return ['action' => $event->action, 'label' => $step[1]];
        }

        return null;
    }
}
