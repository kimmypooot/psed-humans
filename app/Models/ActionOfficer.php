<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/** A PSED evaluator as the monitoring workbook knows them: by initials. */
#[Fillable(['initials', 'name', 'imis_user_id', 'user_id'])]
class ActionOfficer extends Model
{
    /**
     * Initials matched to the CSC RO VIII employees in IMIS: name and IMIS user id.
     * KBMB, BPG, GQC, MVPA, RMA and DDD were confirmed by PSED (DDD is IMIS #21 "Don Mark Phillipe Delmo De Los Reyes",
     * linked by first name and surname); the rest were matched to the one IMIS
     * employee whose initials fit and should be confirmed. Initials not listed here are named in the
     * system by PSED staff (see ActionOfficerController).
     */
    public const KNOWN = [
        'KBMB' => ['Kim Benedick M. Banoyo', 12],
        'BPG' => ['Banello P. Gabon', 29],
        'GQC' => ['Geomelyn Q. Caparroso', 16],
        'MVPA' => ['Maria Victoria P. Arevalo', 9],
        'RMA' => ['Rowell M. Amor', 6],
        'DDD' => ['Don Mark D. Delos Reyes', 21],
        // Matched by initials only.
        'LKL' => ['Lucille K. Lepasana', 32],
        'JMM' => ['Jay M. Merelos', 35],
        'NJL' => ['Norberto J. Lesiguez', 33],
        'NL' => ['Norberto J. Lesiguez', 33],
        'JNAM' => ['Juval Niel A. Morden', 37],
        'CDV' => ['Cerise D. Versoza', 54],
        'GTB' => ['Gervien T. Berecio', 14],
        'BMG' => ['Benjie M. Gelizon', 31],
        'MPA' => ['Maria Victoria P. Arevalo', 9],
    ];

    /** Initials the workbook mistyped => the ones meant (confirmed by PSED). */
    public const ALIASES = [
        'GCQ' => 'GQC',
    ];

    /**
     * Folds each alias officer into the real one: its mechanisms move over (a mechanism that already
     * names both keeps one) and the alias is removed. Safe to run again.
     *
     * @return int mechanisms moved
     */
    public static function mergeAliases(): int
    {
        $moved = 0;

        foreach (self::ALIASES as $alias => $real) {
            $duplicate = self::where('initials', $alias)->first();

            if ($duplicate === null) {
                continue;
            }

            $keep = self::forInitials($real);

            foreach (DB::table('action_officer_hr_mechanism')->where('action_officer_id', $duplicate->id)->get() as $row) {
                $already = DB::table('action_officer_hr_mechanism')->where('hr_mechanism_id', $row->hr_mechanism_id)->where('action_officer_id', $keep->id)->exists();
                $already
                    ? DB::table('action_officer_hr_mechanism')->where('id', $row->id)->delete()
                    : DB::table('action_officer_hr_mechanism')->where('id', $row->id)->update(['action_officer_id' => $keep->id]);
                $moved++;
            }

            $duplicate->delete();
        }

        return $moved;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<HrMechanism, $this> */
    public function mechanisms(): BelongsToMany
    {
        return $this->belongsToMany(HrMechanism::class, 'action_officer_hr_mechanism')->withPivot('position');
    }

    /** The officer for these initials, created on first sight with the name and IMIS person when known. */
    public static function forInitials(string $initials): self
    {
        $initials = mb_strtoupper(trim($initials));
        $initials = self::ALIASES[$initials] ?? $initials;
        [$name, $imisId] = self::KNOWN[$initials] ?? [null, null];
        $officer = self::firstOrCreate(['initials' => $initials], ['name' => $name, 'imis_user_id' => $imisId]);

        // Never overwrite a name PSED typed in; only fill what is still empty.
        $officer->fill(array_filter([
            'name' => $officer->name === null ? $name : null,
            'imis_user_id' => $officer->imis_user_id === null ? $imisId : null,
        ]))->save();

        return $officer->linkAccount();
    }

    /** The officer behind a signed-in user: the one for their IMIS id, else one matched by name, else new. */
    public static function forUser(User $user): self
    {
        $officer = self::where('user_id', $user->id)->first()
            ?? self::where('imis_user_id', $user->imis_user_id)->orderBy('id')->first()
            ?? self::whereNull('user_id')->whereRaw('lower(name) = ?', [mb_strtolower($user->name)])->first();

        if ($officer === null) {
            $words = preg_split('/\s+/', trim($user->name)) ?: [];
            $initials = mb_substr(collect($words)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode(''), 0, 10);
            $officer = self::firstOrCreate(['initials' => $initials], ['name' => $user->name]);
        }

        $officer->update(['user_id' => $user->id, 'imis_user_id' => $officer->imis_user_id ?? $user->imis_user_id, 'name' => $officer->name ?? $user->name]);

        return $officer;
    }

    /** Ties every officer of an IMIS person to their account here, once they have one. */
    public static function linkAccounts(User $user): void
    {
        if ($user->imis_user_id !== null) {
            self::where('imis_user_id', $user->imis_user_id)->whereNull('user_id')->update(['user_id' => $user->id]);
        }
    }

    private function linkAccount(): self
    {
        if ($this->user_id === null && $this->imis_user_id !== null) {
            $this->user_id = User::where('imis_user_id', $this->imis_user_id)->value('id');

            if ($this->user_id !== null) {
                $this->save();
            }
        }

        return $this;
    }
}
