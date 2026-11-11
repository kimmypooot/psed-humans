<?php

namespace Database\Seeders;

use App\Models\FieldOffice;
use Illuminate\Database\Seeder;

/** The Field and Satellite Offices, tied to IMIS's organizational units by `imis_code`. */
class FieldOfficeSeeder extends Seeder
{
    /** Short code => [name, IMIS unit code]. The short codes are the sheet names of the monitoring workbook. */
    public const OFFICES = [
        'FO-Leyte I' => ['CSC Field Office - Leyte I', 'LFO-I'],
        'FO-Leyte II' => ['CSC Field Office - Leyte II', 'LFO-II'],
        'SO-WL' => ['CSC Satellite Office - Western Leyte', 'WLSO'],
        'FO-Biliran' => ['CSC Field Office - Biliran', 'BFO'],
        'FO-SL' => ['CSC Field Office - Southern Leyte', 'SLFO'],
        'FO-S' => ['CSC Field Office - Samar', 'SFO'],
        'FO-NS' => ['CSC Field Office - Northern Samar', 'NSFO'],
        'FO-ES' => ['CSC Field Office - Eastern Samar', 'ESFO'],
    ];

    public function run(): void
    {
        foreach (self::OFFICES as $code => [$name, $imisCode]) {
            FieldOffice::updateOrCreate(['code' => $code], ['name' => $name, 'imis_code' => $imisCode]);
        }
    }
}
