<?php

namespace App\Enums;

/**
 * A staff member's role here. It is not typed in here: it is the role IMIS grants for
 * this system (the role code IMIS returns at sign-in, see ImisSsoController),
 * so a role changed in IMIS takes effect the next time the person opens it.
 *
 * Agencies have no accounts: they submit from the public page.
 */
enum UserRole: string
{
    /** PSED staff: receives, evaluates and returns submissions. */
    case PsedStaff = 'psed_staff';

    /** The PSED Chief: everything PSED staff do, and approves evaluated mechanisms for signature. */
    case PsedChief = 'psed_chief';

    /** The Director IV of the Regional Office: final approver of the signed mechanisms. */
    case Director = 'director';

    /** Field and Satellite Office staff: read-only view of the agencies they oversee. */
    case FieldOffice = 'field_office';

    public static function fromImis(?string $code): ?self
    {
        return self::tryFrom((string) $code);
    }

    public function label(): string
    {
        return match ($this) {
            self::PsedStaff => 'PSED Staff',
            self::PsedChief => 'PSED Chief',
            self::Director => 'Director IV',
            self::FieldOffice => 'Field Office',
        };
    }

    /** PSED staff and the Chief: the people who process submissions. */
    public function isPsed(): bool
    {
        return in_array($this, [self::PsedStaff, self::PsedChief], true);
    }
}
