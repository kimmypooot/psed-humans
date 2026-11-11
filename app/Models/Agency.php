<?php

namespace App\Models;

use Database\Factories\AgencyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'sector', 'head_name', 'head_position', 'listed', 'field_office_id'])]
class Agency extends Model
{
    /** @use HasFactory<AgencyFactory> */
    use HasFactory;

    /** @return BelongsTo<FieldOffice, $this> */
    public function fieldOffice(): BelongsTo
    {
        return $this->belongsTo(FieldOffice::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<HrMechanism, $this> */
    public function mechanisms(): HasMany
    {
        return $this->hasMany(HrMechanism::class);
    }
}
