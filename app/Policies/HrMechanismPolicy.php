<?php

namespace App\Policies;

use App\Enums\MechanismStatus;
use App\Enums\UserRole;
use App\Models\HrMechanism;
use App\Models\User;
use App\Support\MechanismUndo;

/** Who may see and move an HR mechanism. Every workflow route checks this as well as its role. */
class HrMechanismPolicy
{
    public function view(User $user, HrMechanism $mechanism): bool
    {
        return HrMechanism::visibleTo($user)->whereKey($mechanism->getKey())->exists();
    }

    /** PSED staff and the Chief route a submission to an Action Officer, who may be themselves. */
    public function route(User $user, HrMechanism $mechanism): bool
    {
        return $user->isPsed() && in_array($mechanism->status, [MechanismStatus::Received, MechanismStatus::Routed], true);
    }

    /** Only the Action Officer it was routed to acknowledges receipt; that starts the 20-day clock. */
    public function acknowledge(User $user, HrMechanism $mechanism): bool
    {
        return $user->isPsed() && $mechanism->action_officer_id === $user->id && $mechanism->status === MechanismStatus::Routed;
    }

    /** The Action Officer processes: attaches the reviewed HR mechanism and the HR checklist and forwards them to the Chief. */
    public function work(User $user, HrMechanism $mechanism): bool
    {
        return $user->isPsed() && $mechanism->action_officer_id === $user->id && $mechanism->status === MechanismStatus::Processing;
    }

    /** The Chief reviews what the Action Officer forwarded, and decides: approve, for compliance, or back to the officer. */
    public function review(User $user, HrMechanism $mechanism): bool
    {
        return $user->isChief() && $mechanism->status === MechanismStatus::ReviewedByChief;
    }

    /** For Compliance is the Chief's decision, made when reviewing: the Action Officer only forwards. */
    public function returnForCompliance(User $user, HrMechanism $mechanism): bool
    {
        return $this->review($user, $mechanism);
    }

    /** Not approved: the Chief sends it back to the Action Officer to evaluate again. */
    public function returnToOfficer(User $user, HrMechanism $mechanism): bool
    {
        return $this->review($user, $mechanism) && $mechanism->action_officer_id !== null;
    }

    /**
     * Undo the most recent step (see MechanismUndo): whoever took it may take it back. Routing by PSED staff, the acknowledgement
     * and the forwarding by the Action Officer, and the decision by the PSED Chief.
     */
    public function undo(User $user, HrMechanism $mechanism): bool
    {
        $step = MechanismUndo::step($mechanism);

        if ($step === null) {
            return false;
        }

        return match ($step['action']) {
            'routed' => $user->isPsed(),
            'acknowledged', 'forwarded' => $user->isPsed() && $mechanism->action_officer_id === $user->id,
            default => $user->isChief(),
        };
    }

    /** After the Chief decides, the Action Officer generates the transmittal letter. */
    public function generateTransmittal(User $user, HrMechanism $mechanism): bool
    {
        return $this->transmit($user, $mechanism);
    }

    /**
     * The Action Officer prints the generated transmittal, has the Regional Director sign it, and records that here.
     * That is when the agency is emailed.
     */
    public function transmit(User $user, HrMechanism $mechanism): bool
    {
        return $user->isPsed()
            && $mechanism->action_officer_id === $user->id
            && in_array($mechanism->status, [MechanismStatus::Reviewed, MechanismStatus::ForCompliance], true);
    }

    /** The Regional Director, or PSED on their behalf, once the Head of Agency's signed copies are in. */
    public function finalApprove(User $user, HrMechanism $mechanism): bool
    {
        return ($user->role === UserRole::Director || $user->isPsed()) && $mechanism->status === MechanismStatus::TransmittalApproved;
    }

    /** The Action Officer sends the approved copies to the Field Office and the agency, and keeps the third. */
    public function distribute(User $user, HrMechanism $mechanism): bool
    {
        return $user->isPsed()
            && $mechanism->action_officer_id === $user->id
            && $mechanism->status === MechanismStatus::Approved
            && $mechanism->date_distributed === null;
    }

    /** PSED and the Director open the files; Field Offices only see statuses. */
    public function download(User $user, HrMechanism $mechanism): bool
    {
        return $user->role !== UserRole::FieldOffice && $this->view($user, $mechanism);
    }
}
