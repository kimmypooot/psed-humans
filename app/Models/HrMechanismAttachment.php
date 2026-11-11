<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One of the three documents PSED sends back: the reviewed HR mechanism and the HR checklist (attached by the
 * Action Officer) and the transmittal (generated; see App\Support\Transmittal).
 */
#[Fillable(['hr_mechanism_id', 'kind', 'path', 'name', 'uploaded_by', 'signed_by', 'signer_issuer', 'signed_checked_at'])]
class HrMechanismAttachment extends Model
{
    public const KINDS = [
        'reviewed' => 'Reviewed HR mechanism',
        'transmittal' => 'Transmittal',
        'checklist' => 'HR checklist',
    ];

    /** The documents a person uploads; the transmittal is made by the system. */
    public const UPLOADED = ['reviewed', 'checklist'];

    /** @return BelongsTo<HrMechanism, $this> */
    public function mechanism(): BelongsTo
    {
        return $this->belongsTo(HrMechanism::class, 'hr_mechanism_id');
    }

    public function label(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
