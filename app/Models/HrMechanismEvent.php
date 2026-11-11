<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step in the life of a mechanism. Rows are only ever added. */
#[Fillable(['hr_mechanism_id', 'user_id', 'action', 'from_status', 'to_status', 'remarks'])]
class HrMechanismEvent extends Model
{
    public const UPDATED_AT = null;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
