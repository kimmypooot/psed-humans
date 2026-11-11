<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\FieldOffice;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('agencies:sync {--dry-run : Show what would change and save nothing}')]
#[Description('Brings in the agency list of psed-app, the list the public submission page offers')]
class SyncAgencies extends Command
{
    public function handle(): int
    {
        try {
            $rows = DB::connection('psed_app')->table('reference_values as v')
                ->leftJoin('field_offices as f', 'f.id', '=', 'v.field_office_id')
                ->where('v.type', 'agency')
                ->orderBy('v.value')
                ->get(['v.value as name', 'f.name as office']);
        } catch (Throwable $e) {
            $this->error('Could not read the agency list of psed-app ('.config('database.connections.psed_app.database').'): '.$e->getMessage());

            return self::FAILURE;
        }

        if ($rows->isEmpty()) {
            $this->error('psed-app has no agencies; nothing was changed.');

            return self::FAILURE;
        }

        // "Field Office-Leyte I" here, "CSC Field Office - Leyte I" there.
        $offices = FieldOffice::all()->keyBy(fn (FieldOffice $o) => $this->key(preg_replace('/^CSC\s+/i', '', $o->name)));
        $existing = Agency::all()->groupBy(fn (Agency $a) => $this->key($a->name));

        $added = $matched = 0;
        $noOffice = [];
        $listedIds = [];

        DB::beginTransaction();

        foreach ($rows as $row) {
            $office = $row->office ? $offices->get($this->key($row->office)) : null;

            if ($office === null) {
                $noOffice[] = $row->name;
            }

            $found = $existing->get($this->key($row->name))?->first() ?? $this->abbreviated($row->name, $office, $existing);

            if ($found) {
                $found->update(['name' => $row->name, 'listed' => true, 'field_office_id' => $office?->id ?? $found->field_office_id]);
                $matched++;
                $listedIds[] = $found->id;

                continue;
            }

            $created = Agency::create(['name' => $row->name, 'listed' => true, 'field_office_id' => $office?->id]);
            $listedIds[] = $created->id;
            $added++;
        }

        // An agency psed-app no longer lists stays for its history, but is not offered any more.
        $unlisted = Agency::where('listed', true)->whereNotIn('id', $listedIds)->update(['listed' => false]);

        $this->option('dry-run') ? DB::rollBack() : DB::commit();

        $this->info(sprintf(
            '%s%d agencies listed: %d already here, %d added, %d no longer listed.',
            $this->option('dry-run') ? 'Dry run: ' : '',
            $rows->count(),
            $matched,
            $added,
            $unlisted,
        ));

        if ($noOffice) {
            $this->warn(count($noOffice).' have no Field Office in psed-app: '.implode('; ', array_slice($noOffice, 0, 5)).(count($noOffice) > 5 ? '…' : ''));
        }

        return self::SUCCESS;
    }

    /**
     * The old workbook wrote "BFP" or "DA" where psed-app writes the full name. Within the same
     * office, an agency not yet listed whose name is the initials of the full name, the abbreviation
     * in its brackets, or contained in it counts as the same agency, when exactly one fits.
     *
     * @param  Collection<string, Collection<int, Agency>>  $existing
     */
    private function abbreviated(string $name, ?FieldOffice $office, Collection $existing): ?Agency
    {
        if ($office === null) {
            return null;
        }

        $initials = collect(preg_split('/[^A-Za-z0-9]+/', preg_replace('/\(.*?\)/', '', $name)) ?: [])
            ->reject(fn ($w) => $w === '' || in_array(mb_strtolower($w), ['of', 'the', 'and', 'for', 'in', 'at', 'on'], true))
            ->map(fn ($w) => mb_strtolower(mb_substr($w, 0, 1)))->implode('');
        $bracket = preg_match('/\(([^)]+)\)/', $name, $m) ? $this->key($m[1]) : null;
        $full = $this->key($name);

        $fits = $existing->flatten()->filter(function (Agency $a) use ($office, $initials, $bracket, $full) {
            if ($a->listed || $a->field_office_id !== $office->id) {
                return false;
            }

            $k = $this->key($a->name);

            return $k === $initials
                || ($bracket !== null && $k === $bracket)
                || (strlen($k) >= 8 && (str_contains($full, $k) || str_contains($k, $full)));
        });

        return $fits->count() === 1 ? $fits->first() : null;
    }

    /** Names that differ only in case, spacing, dashes or punctuation are the same agency. */
    private function key(string $name): string
    {
        return preg_replace('/[^a-z0-9]+/', '', mb_strtolower($name));
    }
}
