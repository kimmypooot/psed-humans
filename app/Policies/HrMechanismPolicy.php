<?php

namespace App\Policies;

use App\Enums\MechanismStatus;
use App\Enums\UserRole;
use App\Models\HrMechanism;
use App\Models\User;

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

    /** The Chief reviews what the Action Officer forwarded, and decides: for approval or for compliance. */
    public function review(User $user, HrMechanism $mechanism): bool
    {
        return $user->isChief() && $mechanism->status === MechanismStatus::ForChiefReview;
    }

    /** For Compliance is the Chief's decision, made when reviewing: the Action Officer only forwards. */
    public function returnForCompliance(User $user, HrMechanism $mechanism): bool
    {
        return $this->review($user, $mechanism);
    }

    /** The Director IV, or PSED on their behalf, once the signed copies are in. */
    public function finalApprove(User $user, HrMechanism $mechanism): bool
    {
        return ($user->role === UserRole::Director || $user->isPsed()) && $mechanism->status === MechanismStatus::ReviewedByChief;
    }

    /** PSED and the Director open the files; Field Offices only see statuses. */
    public function download(User $user, HrMechanism $mechanism): bool
    {
        return $user->role !== UserRole::FieldOffice && $this->view($user, $mechanism);
    }
}
