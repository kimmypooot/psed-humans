<?php

namespace App\Console\Commands;

use App\Models\ActionOfficer;
use App\Models\HrMechanism;
use App\Models\User;
use App\Support\ActionOfficers;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mechanisms:link-officers')]
#[Description('Turns the stored processor initials of imported mechanisms into Action Officers (one or two each)')]
class LinkMechanismOfficers extends Command
{
    public function handle(): int
    {
        $linked = 0;
        $blank = 0;

        HrMechanism::where('is_legacy', true)->whereNotNull('processor')->where('processor', '!=', '')->whereDoesntHave('officers')
            ->chunkById(500, function ($mechanisms) use (&$linked, &$blank) {
                foreach ($mechanisms as $mechanism) {
                    ActionOfficers::sync($mechanism, $mechanism->processor) > 0 ? $linked++ : $blank++;
                }
            });

        $merged = ActionOfficer::mergeAliases();

        // Name each officer and tie them to their IMIS person (ActionOfficer::KNOWN); a name PSED typed in is kept.
        foreach (ActionOfficer::all() as $officer) {
            ActionOfficer::forInitials($officer->initials);
        }

        foreach (User::whereNotNull('imis_user_id')->get() as $user) {
            ActionOfficer::linkAccounts($user);
        }

        $this->info("Linked {$linked} mechanisms ({$blank} had no readable initials; {$merged} moved from a mistyped set of initials to the right one).");
        $this->table(['Initials', 'Name', 'IMIS id', 'Signed in here', 'Mechanisms'], ActionOfficer::withCount('mechanisms')->with('user')->orderByDesc('mechanisms_count')->get()
            ->map(fn ($o) => [$o->initials, $o->name ?? '(not named yet)', $o->imis_user_id ?? '-', $o->user ? 'yes' : 'no', $o->mechanisms_count])->all());

        return self::SUCCESS;
    }
}
