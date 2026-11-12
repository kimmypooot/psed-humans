<?php

namespace App\Console\Commands;

use App\Enums\MechanismStatus;
use App\Models\HrMechanism;
use App\Support\StaffNotices;
use App\Support\Turnaround;
use Illuminate\Console\Command;

/**
 * Tells the Action Officer, in the bell, when a mechanism is within three working days of its 20-day
 * turnaround and again once it is past it (the Chief hears about the overdue ones too). Each
 * kind is sent once per mechanism, so running it every morning never repeats a notice.
 */
class RemindDueMechanisms extends Command
{
    protected $signature = 'mechanisms:remind-due {--days=3 : How many working days ahead counts as due soon}';

    protected $description = 'Notify Action Officers of mechanisms that are close to or past the 20-day turnaround';

    public function handle(): int
    {
        $ahead = max(0, (int) $this->option('days'));
        $sent = 0;

        HrMechanism::current()
            ->whereIn('status', [MechanismStatus::Routed, MechanismStatus::Processing, MechanismStatus::ReviewedByChief])
            ->whereNotNull('due_at')->whereNotNull('action_officer_id')
            ->with('agency')
            ->each(function (HrMechanism $m) use ($ahead, &$sent) {
                $left = Turnaround::daysLeft($m->due_at);

                if ($left <= $ahead && StaffNotices::deadline($m, $left)) {
                    $sent++;
                }
            });

        $this->info("Sent {$sent} reminder".($sent === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
