<?php

namespace App\Support;

use App\Enums\MechanismStatus;
use App\Enums\UserRole;
use App\Models\HrMechanism;
use App\Models\User;
use App\Notifications\MechanismActivity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Who in PSED is told what, in the bell. One place, so every step of the process tells the right
 * people and never the person who just did it. A failed notice never undoes a step: it is logged.
 */
class StaffNotices
{
    /** A new submission from the public page: every PSED staff member and the Chief, since anyone may route it. */
    public static function submitted(HrMechanism $m): void
    {
        self::send(self::psed(), $m, 'submitted', 'New submission to route', self::what($m).' was submitted and is waiting to be routed to an Action Officer.');
    }

    /** The agency answered a request for compliance: back to the officer it was with, or to PSED to route. */
    public static function resubmitted(HrMechanism $m): void
    {
        $body = self::what($m).' sent a corrected file.';

        if ($m->action_officer_id) {
            self::send(self::users([$m->action_officer_id]), $m, 'resubmitted', 'Corrected file received', $body.' It is back with you to acknowledge.');

            return;
        }

        self::send(self::psed(), $m, 'resubmitted', 'Corrected file received', $body.' It needs to be routed.');
    }

    /** PSED routed it: the officer is told, unless they routed it to themselves. */
    public static function routed(HrMechanism $m, User $by, User $officer): void
    {
        self::send(self::users([$officer->id], except: $by), $m, 'routed', 'Routed to you', self::what($m).' was routed to you by '.$by->name.'. Acknowledge it to start the 20-day clock.');
    }

    /** The Chief did not approve it: the officer who evaluated it is told what to look at again. */
    public static function returnedToOfficer(HrMechanism $m, User $by, string $remarks): void
    {
        self::send(self::users([$m->action_officer_id], except: $by), $m, 'returned_to_officer', 'Returned for re-evaluation', self::what($m).' was returned by '.$by->name.': '.str($remarks)->limit(120));
    }

    /** A step was taken back: the person it was waiting on, or had been waiting on, is told so their bell is not stale. */
    public static function undone(HrMechanism $m, User $by, string $label, string $reason): void
    {
        $people = match ($m->status) {
            MechanismStatus::ReviewedByChief, MechanismStatus::Processing => self::users([$m->action_officer_id], except: $by),
            default => collect(),
        };

        // Withdrawn from the Chief: the Chief hears it is no longer waiting for them.
        if ($m->status === MechanismStatus::Processing && $by->id === $m->action_officer_id) {
            $people = User::where('role', UserRole::PsedChief)->get();
        }

        self::send($people, $m, 'undone', $label, self::what($m).': '.$label.' by '.$by->name.'. '.str($reason)->limit(120));
    }

    /**
     * The Chief decided: the officer prints the transmittal, has the Regional Director sign it, then sends it to the agency.
     * Nothing has gone to the agency yet.
     */
    public static function readyToTransmit(HrMechanism $m, User $by, bool $forCompliance): void
    {
        $what = $forCompliance ? 'marked For Compliance by '.$by->name.': '.str((string) $m->remarks)->limit(120) : 'approved by '.$by->name;

        self::send(
            self::users([$m->action_officer_id], except: $by),
            $m,
            $forCompliance ? 'marked_for_compliance' : 'reviewed',
            $forCompliance ? 'For Compliance: generate the transmittal' : 'Reviewed: generate the transmittal',
            self::what($m).' was '.$what.'. Generate the transmittal, print it for the Regional Director\'s signature, then record it in the system to email the agency.',
        );
    }

    /** Final approval: the officer and the Chief hear it is done. */
    public static function approved(HrMechanism $m, User $by): void
    {
        $chiefs = User::where('role', UserRole::PsedChief)->pluck('id')->all();

        self::send(self::users([$m->action_officer_id, ...$chiefs], except: $by), $m, 'approved', 'Final approval', self::what($m).' received final approval.');
    }

    /** The turnaround is near or over: only the officer who has it, once per kind (the Chief too when it is overdue). */
    public static function deadline(HrMechanism $m, int $daysLeft): bool
    {
        $kind = $daysLeft < 0 ? 'overdue' : 'due_soon';
        $title = $kind === 'overdue' ? 'Past the 20-day turnaround' : 'Due in '.$daysLeft.' working '.($daysLeft === 1 ? 'day' : 'days');
        $body = self::what($m).($kind === 'overdue' ? ' is '.abs($daysLeft).' working '.(abs($daysLeft) === 1 ? 'day' : 'days').' past its target date.' : ' is due on '.$m->due_at->format('M j, Y').'.');

        $chiefs = $kind === 'overdue' ? User::where('role', UserRole::PsedChief)->pluck('id')->all() : [];
        $people = self::users([$m->action_officer_id, ...$chiefs])->reject(fn (User $u) => self::already($u, $m, $kind));

        self::send($people, $m, $kind, $title, $body);

        return $people->isNotEmpty();
    }

    /** "Municipal Government of Javier · MSP". */
    private static function what(HrMechanism $m): string
    {
        $m->loadMissing('agency');

        return ($m->agency?->name ?? 'An agency').' · '.($m->title ?: $m->mechanism_type->value);
    }

    /** @return Collection<int, User> */
    private static function psed(): Collection
    {
        return User::whereIn('role', [UserRole::PsedStaff, UserRole::PsedChief])->get();
    }

    /**
     * @param  list<int|null>  $ids
     * @return Collection<int, User>
     */
    private static function users(array $ids, ?User $except = null): Collection
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return User::whereIn('id', $ids)->when($except, fn ($q) => $q->whereKeyNot($except->id))->get();
    }

    /** Whether this person already has a notice of this kind for this mechanism since it was last routed or resent. */
    private static function already(User $user, HrMechanism $m, string $kind): bool
    {
        $since = $m->acknowledged_at ?? $m->routed_at ?? $m->date_submitted;

        return $user->notifications()->where('type', MechanismActivity::class)
            ->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->get()
            ->contains(fn ($n) => ($n->data['mechanism_id'] ?? null) === $m->id && ($n->data['kind'] ?? null) === $kind);
    }

    /** @param  Collection<int, User>  $people */
    private static function send(Collection $people, HrMechanism $m, string $kind, string $title, string $body): void
    {
        foreach ($people as $person) {
            try {
                $person->notify(new MechanismActivity($m, $kind, $title, $body));
            } catch (Throwable $e) {
                Log::warning('Staff notice failed', ['mechanism' => $m->id, 'user' => $person->id, 'kind' => $kind, 'message' => $e->getMessage()]);
            }
        }
    }
}
