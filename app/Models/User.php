<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A person, as IMIS last described them. A stub: created and rewritten
 * from IMIS at each sign-in (ImisSsoController) and never given a password.
 * Agencies have no accounts: they use the public submission page.
 */
#[Fillable(['imis_user_id', 'username', 'name', 'email', 'position', 'role', 'photo_url', 'field_office_id', 'last_login_at'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'imis_user_id' => 'integer',
            'role' => UserRole::class,
            'last_login_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FieldOffice, $this> */
    public function fieldOffice(): BelongsTo
    {
        return $this->belongsTo(FieldOffice::class);
    }

    public function isPsed(): bool
    {
        return $this->role->isPsed();
    }

    public function isChief(): bool
    {
        return $this->role === UserRole::PsedChief;
    }
}
