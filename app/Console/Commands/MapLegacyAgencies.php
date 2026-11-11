<?php

namespace App\Console\Commands;

use App\Models\Agency;
use App\Models\AgencyAlias;
use App\Models\HrMechanism;
use App\Support\AgencyMapper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('agencies:map-legacy {--dry-run : Show what would change and save nothing} {--show : List every mapping, not only the ones left over}')]
#[Description('Moves the mechanisms of workbook agency names (e.g. "MGO ALLEN, NORTHERN SAMAR") to the official agencies, and removes rows that are not agencies')]
class MapLegacyAgencies extends Command
{
    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $mapped = [];
        $junk = [];
        $left = [];

        DB::beginTransaction();

        try {
            foreach (Agency::where('listed', false)->with('fieldOffice')->withCount('mechanisms')->orderBy('field_office_id')->orderBy('name')->get() as $agency) {
                if (AgencyMapper::isNotAnAgency($agency->name)) {
                    $junk[] = [$agency->fieldOffice?->code, $agency->name, $agency->mechanisms_count];
                    HrMechanism::where('agency_id', $agency->id)->delete();
                    $agency->delete();

                    continue;
                }

                $target = AgencyMapper::resolve($agency->name, $agency->field_office_id);

                if ($target === null || $target->is($agency)) {
                    $left[] = [$agency->fieldOffice?->code, $agency->name, $agency->sector ?? '-', $agency->mechanisms_count];

                    continue;
                }

                HrMechanism::where('agency_id', $agency->id)->update(['agency_id' => $target->id]);
                AgencyAlias::updateOrCreate(
                    ['name_key' => AgencyMapper::key($agency->name), 'field_office_id' => $agency->field_office_id],
                    ['agency_id' => $target->id, 'name' => $agency->name],
                );
                $mapped[] = [$agency->fieldOffice?->code, $agency->name, $target->name, $agency->mechanisms_count, $target->field_office_id === $agency->field_office_id ? '' : 'other office'];
                $agency->delete();
            }

            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('show')) {
            $this->table(['Sheet', 'Workbook name', 'Official agency', 'Mechanisms', 'Note'], $mapped);
        }

        if ($junk !== []) {
            $this->line('Not agencies (removed with their empty rows):');
            $this->table(['Sheet', 'Name', 'Rows'], $junk);
        }

        if ($left !== []) {
            $this->line('No confident match; left as they are:');
            $this->table(['Sheet', 'Workbook name', 'Sector', 'Mechanisms'], $left);
        }

        $moved = array_sum(array_column($mapped, 3));
        $other = count(array_filter($mapped, fn ($r) => $r[4] !== ''));
        $this->info(($dry ? 'Dry run: ' : '').count($mapped)." workbook names mapped to official agencies ({$moved} mechanisms, {$other} names point to an agency of another office); ".count($junk).' non-agency rows removed; '.count($left).' names left ('.array_sum(array_column($left, 3)).' mechanisms).');

        return self::SUCCESS;
    }
}
