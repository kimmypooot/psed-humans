<?php

namespace App\Support;

use App\Models\Agency;
use App\Models\AgencyAlias;
use Illuminate\Support\Collection;

/**
 * Maps the agency names of the monitoring workbook to the official (listed) agencies.
 * It is deliberately cautious: a name that fits more than one agency, or none, is left alone.
 */
class AgencyMapper
{
    /** Rows of the workbook that are signature blocks, not agencies. */
    private const NOT_AGENCIES = ['ACTION OFFICER', 'ADMINISTRATIVE AIDE V', 'NOEL L CESAR'];

    /** Provinces, longest first so "SOUTHERN LEYTE" is not read as "LEYTE". */
    private const PROVINCES = ['EASTERN SAMAR', 'NORTHERN SAMAR', 'SOUTHERN LEYTE', 'BILIRAN', 'LEYTE', 'SAMAR'];

    /** Abbreviations => the official name (compared by key). */
    private const ABBREVIATIONS = [
        'BJMP' => 'Bureau of Jail Management and Penology Regional Office VIII',
        'BFAR' => 'Bureau of Fisheries and Aquatic Resources Regional Office VIII',
        'DENR RO8' => 'Department of Environment and Natural Resources Regional Office VIII',
        'DILG' => 'Department of the Interior and Local Government Region VIII',
        'DOJ PPA' => 'Parole and Probation Administration Region VIII',
        'DOLE' => 'Department of Labor and Employment Regional Office VIII',
        'DOST' => 'Department of Science and Technology Regional Office VIII',
        'NEDA' => 'National Economic Development Authority Region VIII',
        'TESDA' => 'Technical Education And Skills Development Authority Region VIII',
        'DA' => 'Department of Agriculture Regional Field Office VIII',
        'DAR' => 'Department of Agrarian Reform Region VIII',
        'DBM' => 'Department of Budget and Management Regional Office VIII',
        'DPWH' => 'Department Of Public Works And Highways Regional Office VIII',
        'DPWH RO VIII' => 'Department Of Public Works And Highways Regional Office VIII',
        'DSWD FO8' => 'Department of Social Welfare and Development Field Office VIII',
        'DTI' => 'Department of Trade and Industry Region VIII',
        'EVRMC' => 'Eastern Visayas Medical Center',
        'LTO' => 'Land Transportation Office Regional Office 8',
        'LNU' => 'Leyte Normal University',
        'SSU' => 'Samar State University',
        'SLSU' => 'Southern Leyte State University',
        'NWSSU' => 'Northwest Samar State University',
        'BIPSU' => 'Biliran Province State University (BiPSU)',
        'PHIL SCIENCE HIGH SCHOOL' => 'Philippine Science High School - Eastern Visayas Campus',
        'SILVINO LOBOS' => 'Municipal Government of Silvino Lubos, Northern Samar',
    ];

    /** @var Collection<string, Agency>|null */
    private static ?Collection $listed = null;

    public static function forgetIndex(): void
    {
        self::$listed = null;
    }

    /** "MGO San Juan (Cabalian)" and "Municipal Government of San Juan" compare by the same plain upper-case words. */
    public static function key(string $name): string
    {
        $s = mb_strtoupper(trim($name));
        $s = strtr($s, ['Ñ' => 'N', '&' => ' AND ']);
        $s = preg_replace('/\([^)]*\)/', ' ', $s) ?? $s;
        $s = preg_replace('/[^A-Z0-9 ]+/', ' ', $s) ?? $s;
        $s = preg_replace(['/\bSTA\b/', '/\bSTO\b/', '/\bST\b/', '/\bW D\b/', '/\bWD\b/', '/\b(E|EAST)\s+SAMAR\b/', '/\bN\s+SAMAR\b/'],
            ['SANTA', 'SANTO', 'SAINT', 'WATER DISTRICT', 'WATER DISTRICT', 'EASTERN SAMAR', 'NORTHERN SAMAR'], $s) ?? $s;

        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    public static function isNotAnAgency(string $name): bool
    {
        return in_array(self::key($name), self::NOT_AGENCIES, true);
    }

    /** The official agency this workbook name stands for, or null when it is unknown or could be several. */
    public static function resolve(string $name, ?int $officeId): ?Agency
    {
        $key = self::key($name);

        if ($key === '' || self::isNotAnAgency($name)) {
            return null;
        }

        $alias = AgencyAlias::where('name_key', $key)->where('field_office_id', $officeId)->first() ?? AgencyAlias::where('name_key', $key)->whereNull('field_office_id')->first();

        if ($alias !== null) {
            return $alias->agency;
        }

        $byKey = self::listed()->groupBy(fn (Agency $a) => self::key($a->name));

        if (isset($byKey[$key]) && $byKey[$key]->count() === 1) {
            return $byKey[$key]->first();
        }

        foreach (self::ABBREVIATIONS as $abbreviation => $official) {
            if (self::key($abbreviation) === $key) {
                return $byKey[self::key($official)][0] ?? null;
            }
        }

        // DepEd: "DEPED - DIVISION OF BILIRAN", "DEPED Ormoc City Division", "DEPED RO8".
        if (preg_match('/^DEPED (.+)$/', $key, $m)) {
            $subject = trim(preg_replace('/\b(DIVISION|OF|SCHOOLS|OFFICE)\b/', ' ', $m[1]) ?? '');
            $subject = trim(preg_replace('/\s+/', ' ', $subject) ?? '');
            $target = preg_match('/^R(O)? ?0?8$/', $subject) ? 'DEPARTMENT OF EDUCATION REGIONAL OFFICE VIII' : 'DEPARTMENT OF EDUCATION SCHOOLS DIVISION OFFICE OF '.$subject;

            return ($byKey[$target] ?? collect())->count() === 1 ? $byKey[$target][0] : null;
        }

        // A water district: "NAVAL W.D.", "BAYBAY WATER DISTRICT" (official: "Baybay City Water District").
        if (preg_match('/^(.+) WATER DISTRICT$/', $key, $m)) {
            return self::pick(collect([$m[1].' WATER DISTRICT', $m[1].' CITY WATER DISTRICT'])->flatMap(fn ($k) => $byKey[$k] ?? []), null, $officeId);
        }

        if (preg_match('/^PGO (.+)$/', $key, $m) || preg_match('/^PROVINCE OF (.+)$/', $key, $m)) {
            return self::pick($byKey['PROVINCIAL GOVERNMENT OF '.$m[1]] ?? [], null, $officeId);
        }

        if (preg_match('/^CGO (.+)$/', $key, $m)) {
            return self::pick($byKey['CITY GOVERNMENT OF '.$m[1]] ?? [], null, $officeId);
        }

        // "MGO ALLEN, NORTHERN SAMAR", "MGO CAIBIRAN, BILIRAN", "LAWAAN, E. SAMAR", or just "ALLEN".
        $place = preg_replace('/^(MGO|MUNICIPAL GOVERNMENT OF) /', '', $key) ?? $key;
        $province = null;

        foreach (self::PROVINCES as $p) {
            if (str_ends_with($place, ' '.$p)) {
                $province = $p;
                $place = trim(substr($place, 0, -strlen($p)));
                break;
            }
        }

        // A bare "BILIRAN", "LEYTE" or "SAMAR" could be the province or the municipality: not guessed.
        if ($place === '' || in_array($place, self::PROVINCES, true)) {
            return null;
        }

        $candidates = self::listed()->filter(function (Agency $a) use ($place, $province) {
            $k = self::key($a->name);

            return (str_starts_with($k, 'MUNICIPAL GOVERNMENT OF '.$place.' ') || str_starts_with($k, 'CITY GOVERNMENT OF '.$place))
                && ($province === null || str_ends_with($k, ' '.$province));
        })->filter(function (Agency $a) use ($place) {
            // "MUNICIPAL GOVERNMENT OF SAN JOSE DE BUAN SAMAR" must not match "SAN JOSE".
            $rest = preg_replace('/^(MUNICIPAL|CITY) GOVERNMENT OF /', '', self::key($a->name)) ?? '';
            $rest = preg_replace('/ ('.implode('|', self::PROVINCES).')$/', '', $rest) ?? '';

            return $rest === $place;
        });

        return self::pick($candidates, $province, $officeId);
    }

    /** One candidate, or the one in the sheet's own office when there are several; otherwise null. */
    private static function pick(iterable $candidates, ?string $province, ?int $officeId): ?Agency
    {
        $candidates = collect($candidates)->values();

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $local = $candidates->where('field_office_id', $officeId);

        return $local->count() === 1 ? $local->first() : null;
    }

    /** @return Collection<int, Agency> */
    private static function listed(): Collection
    {
        return self::$listed ??= Agency::where('listed', true)->get(['id', 'name', 'field_office_id']);
    }
}
