<?php

namespace App\Support;

use App\Models\ActionOfficer;
use App\Models\HrMechanism;

/** Reads the workbook's PROCESSOR cell ("RMA", "GQC & RMA", "c/o BPG", "NL/RMA") into officers. */
class ActionOfficers
{
    /**
     * The initials written in a cell, in order, without repeats.
     *
     * @return list<string>
     */
    public static function parse(?string $cell): array
    {
        $text = mb_strtoupper((string) $cell);
        $text = preg_replace('#\bC/O\b#', ' ', $text) ?? $text;

        return collect(preg_split('#\s*(?:&|/|,|\+|\bAND\b)\s*|\s+#', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn ($t) => trim($t, " .;:()"))
            ->filter(fn ($t) => preg_match('/^[A-Z]{2,10}$/', $t) === 1)
            ->unique()
            ->values()
            ->all();
    }

    /** Replaces the officers of a mechanism with those written in the cell; returns how many. */
    public static function sync(HrMechanism $mechanism, ?string $cell): int
    {
        $ids = [];

        foreach (self::parse($cell) as $i => $initials) {
            $ids[ActionOfficer::forInitials($initials)->id] = ['position' => $i + 1];
        }

        $mechanism->officers()->sync($ids);

        return count($ids);
    }
}
