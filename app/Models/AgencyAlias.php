<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['agency_id', 'field_office_id', 'name', 'name_key'])]
class AgencyAlias extends Model
{
    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }
}
