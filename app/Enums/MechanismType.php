<?php

namespace App\Enums;

/** The kinds of HR mechanism agencies submit to the Regional Office. */
enum MechanismType: string
{
    case Msp = 'MSP';
    case Rsp = 'RSP';
    case Spms = 'SPMS';
    case Praise = 'PRAISE';
    case LearningDevelopment = 'L&D';
    case Grievance = 'GM';
    case Adr = 'ADRSHC';
    case PerformanceManagement = 'PM';
    case Other = 'Other';

    public function label(): string
    {
        return match ($this) {
            self::Msp => 'Merit Selection Plan (MSP)',
            self::Rsp => 'Recruitment, Selection and Placement (RSP)',
            self::Spms => 'Strategic Performance Management System (SPMS)',
            self::Praise => 'PRAISE / Rewards and Recognition',
            self::LearningDevelopment => 'Learning and Development (L&D)',
            self::Grievance => 'Grievance Machinery',
            self::Adr => 'Administrative Disciplinary Rules on Sexual Harassment Cases (ADRSHC)',
            self::PerformanceManagement => 'Performance Management (PM)',
            self::Other => 'Other HR mechanism',
        };
    }

    /** Guesses the type from what an agency or the old workbook called it. */
    public static function guess(?string $text): self
    {
        $t = mb_strtolower(trim((string) $text));

        return match (true) {
            str_contains($t, 'msp'), str_contains($t, 'merit') => self::Msp,
            str_contains($t, 'spms') => self::Spms,
            str_contains($t, 'praise'), str_contains($t, 'r&r'), str_contains($t, 'r & r'), str_contains($t, 'reward'), str_contains($t, 'award') => self::Praise,
            str_contains($t, 'l&d'), str_contains($t, 'l & d'), str_contains($t, 'learning') => self::LearningDevelopment,
            str_contains($t, 'grievance'), $t === 'gm' => self::Grievance,
            str_contains($t, 'adr'), str_contains($t, 'shc') => self::Adr,
            str_contains($t, 'rsp'), str_contains($t, 'recruit') => self::Rsp,
            $t === 'pm', str_contains($t, 'pm ers'), str_contains($t, 'performance') => self::PerformanceManagement,
            default => self::Other,
        };
    }
}
