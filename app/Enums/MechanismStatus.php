<?php

namespace App\Enums;

/**
 * Where an HR mechanism is, in the order of the process the PSED Chief approved:
 * received, routed to an Action Officer, processed (the 20-day clock), reviewed by the PSED Chief
 * (or sent back to the officer), then the Chief's decision (Reviewed, or For Compliance), the
 * transmittal signed by the Regional Director and sent by the Action Officer (Transmittal
 * (Approved) or Transmittal (For Compliance), which is when the agency is emailed), and finally
 * Approved once the Head of Agency's signed copies are approved by the Regional Director.
 */
enum MechanismStatus: string
{
    case Received = 'Received by PSED';
    case Routed = 'Routed to Action Officer';
    case Processing = 'Processing/Evaluating';
    /** Forwarded by the Action Officer; waiting for the Chief's decision. */
    case ReviewedByChief = 'Reviewed by PSED Chief';
    /** The Chief approved: the Action Officer prints the transmittal for the Regional Director's signature. */
    case Reviewed = 'Reviewed';
    /** The Chief marked it For Compliance: same, but the agency is told what to correct. */
    case ForCompliance = 'For Compliance';
    /** The signed transmittal and the documents were emailed: the agency is signing three copies. */
    case TransmittalApproved = 'Transmittal (Approved)';
    /** The signed transmittal and the documents were emailed: the agency corrects and resubmits. */
    case TransmittalCompliance = 'Transmittal (For Compliance)';
    case Approved = 'Approved';

    /** The Badge tone: grey until routed, blue while PSED works on it, orange for signing, red for the agency, green. */
    public function tone(): string
    {
        return match ($this) {
            self::Received => 'neutral',
            self::Routed, self::Processing, self::ReviewedByChief, self::Reviewed, self::ForCompliance => 'info',
            self::TransmittalApproved => 'warning',
            self::TransmittalCompliance => 'danger',
            self::Approved => 'success',
        };
    }

    /** Whether the 20-day turnaround clock is running in this status. */
    public function clockRuns(): bool
    {
        return in_array($this, [self::Routed, self::Processing, self::ReviewedByChief], true);
    }

    /** Whether the agency has been told what it needs to correct, and may answer. */
    public function isWithAgencyForCompliance(): bool
    {
        return $this === self::TransmittalCompliance;
    }

    /** Maps a status written in the monitoring workbook to one of ours. */
    public static function fromLegacy(?string $text, bool $hasApprovedDate, bool $hasReturnedDate): self
    {
        $text = mb_strtolower(trim((string) $text));

        return match (true) {
            str_contains($text, 'approved') => self::Approved,
            str_contains($text, 'return'), str_contains($text, 'complian'), str_contains($text, 'revision') => self::TransmittalCompliance,
            $hasApprovedDate => self::Approved,
            $hasReturnedDate => self::TransmittalCompliance,
            default => self::Received,
        };
    }
}
