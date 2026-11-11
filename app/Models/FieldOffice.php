<?php

namespace App\Models;

use Database\Factories\FieldOfficeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A CSC Field or Satellite Office, and the agencies it oversees. */
#[Fillable(['name', 'code', 'imis_code'])]
class FieldOffice extends Model
{
    /** @use HasFactory<FieldOfficeFactory> */
    use HasFactory;

    /** @return HasMany<Agency, $this> */
    public function agencies(): HasMany
    {
        return $this->hasMany(Agency::class);
    }
}
