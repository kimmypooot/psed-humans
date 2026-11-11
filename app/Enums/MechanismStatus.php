<?php

namespace App\Enums;

/**
 * Where an HR mechanism is, in the order it normally goes:
 * received, routed to an Action Officer, processed (the 20-day clock), reviewed by the PSED Chief,
 * approved after the Head of Agency signs. For Compliance is the way back to the agency.
 */
enum MechanismStatus: string
{
    case Received = 'Received by PSED';
    case Routed = 'Routed to Action Officer';
    case Processing = 'Processing/Evaluating';
    case ForChiefReview = 'For PSED Chief Review';
    case ReviewedByChief = 'Reviewed by PSED Chief';
    case ForCompliance = 'For Compliance';
    case Approved = 'Approved';

    /** The Badge tone: grey until routed, blue while PSED works on it, red for the agency, orange for signing, green. */
    public function tone(): string
    {
        return match ($this) {
            self::Received => 'neutral',
            self::Routed, self::Processing, self::ForChiefReview => 'info',
            self::ReviewedByChief => 'warning',
            self::ForCompliance => 'danger',
            self::Approved => 'success',
        };
    }

    /** Whether the 20-day turnaround clock is running in this status. */
    public function clockRuns(): bool
    {
        return in_array($this, [self::Processing, self::ForChiefReview], true);
    }

    /** Maps a status written in the monitoring workbook to one of ours. */
    public static function fromLegacy(?string $text, bool $hasApprovedDate, bool $hasReturnedDate): self
    {
        $text = mb_strtolower(trim((string) $text));

        return match (true) {
            str_contains($text, 'approved') => self::Approved,
            str_contains($text, 'return'), str_contains($text, 'complian'), str_contains($text, 'revision') => self::ForCompliance,
            $hasApprovedDate => self::Approved,
            $hasReturnedDate => self::ForCompliance,
            default => self::Received,
        };
    }
}
