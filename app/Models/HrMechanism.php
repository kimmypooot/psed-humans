<?php

namespace App\Models;

use App\Enums\MechanismStatus;
use App\Enums\MechanismType;
use App\Enums\UserRole;
use Database\Factories\HrMechanismFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'agency_id', 'tracking_code', 'mechanism_type', 'title', 'copies', 'document_path', 'document_name', 'status', 'processor',
    'action_officer_id', 'routed_at', 'acknowledged_at', 'due_at',
    'date_submitted', 'date_processed', 'date_evaluated', 'date_reviewed', 'date_returned', 'date_transmitted', 'date_submitted_to_rd', 'date_approved', 'date_distributed',
    'remarks', 'receipt_details', 'location', 'submitted_by', 'contact_email', 'contact_name', 'is_legacy',
])]
class HrMechanism extends Model
{
    /** @use HasFactory<HrMechanismFactory> */
    use HasFactory;

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'status' => MechanismStatus::class,
            'mechanism_type' => MechanismType::class,
            'routed_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'due_at' => 'datetime',
            'date_submitted' => 'datetime',
            'date_processed' => 'datetime',
            'date_evaluated' => 'datetime',
            'date_reviewed' => 'datetime',
            'date_returned' => 'datetime',
            'date_transmitted' => 'datetime',
            'date_submitted_to_rd' => 'datetime',
            'date_approved' => 'datetime',
            'date_distributed' => 'datetime',
            'is_legacy' => 'boolean',
        ];
    }

    /** The next free code of the year, for example HRM-2027-00042. */
    public static function nextTrackingCode(): string
    {
        $year = now()->year;
        $last = self::where('tracking_code', 'like', 'HRM-'.$year.'-%')->orderByDesc('tracking_code')->value('tracking_code');
        $next = $last ? ((int) substr($last, -5)) + 1 : 1;

        return sprintf('HRM-%d-%05d', $year, $next);
    }

    /** @return BelongsTo<Agency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /** @return BelongsTo<User, $this> */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    /** @return BelongsTo<User, $this> */
    public function actionOfficer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'action_officer_id');
    }

    /**
     * Every officer of this mechanism, first named first. Old mechanisms can have two ("GQC & RMA").
     *
     * @return BelongsToMany<ActionOfficer, $this>
     */
    public function officers(): BelongsToMany
    {
        return $this->belongsToMany(ActionOfficer::class, 'action_officer_hr_mechanism')->withPivot('position')->orderByPivot('position');
    }

    /** @return HasMany<HrMechanismEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(HrMechanismEvent::class)->orderBy('id');
    }

    /** @return HasMany<HrMechanismAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(HrMechanismAttachment::class)->orderBy('id');
    }

    /**
     * The newest file of each kind: the reviewed mechanism, the transmittal and the checklist.
     *
     * @return Collection<int, HrMechanismAttachment>
     */
    public function currentAttachments(): Collection
    {
        return $this->attachments()->whereNull('reverted_at')->get()->groupBy('kind')->map->last()->values()->pipe(fn ($items) => new Collection($items->all()));
    }

    /**
     * Whether the Action Officer generated the transmittal for the Chief's decision. A transmittal from an earlier round
     * (before the Chief decided again) does not count.
     */
    public function hasGeneratedTransmittal(): bool
    {
        return $this->attachments()->where('kind', 'transmittal')
            ->when($this->date_reviewed, fn ($q) => $q->where('created_at', '>=', $this->date_reviewed))
            ->exists();
    }

    /**
     * Whether the two documents the Action Officer attaches are there: the reviewed HR mechanism and the
     * HR checklist. The transmittal is not one of them; the Action Officer generates it after the Chief
     * approves or marks it For Compliance.
     */
    public function hasReviewDocuments(): bool
    {
        $kinds = $this->currentAttachments()->pluck('kind');

        return $kinds->contains('reviewed') && $kinds->contains('checklist');
    }

    /**
     * What a user may see: PSED and the Director see all, a Field Office its own agencies.
     *
     * @param  Builder<HrMechanism>  $query
     * @return Builder<HrMechanism>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match (true) {
            $user->role->isPsed(), $user->role === UserRole::Director => $query,
            $user->role === UserRole::FieldOffice => $query->whereHas('agency', fn (Builder $q) => $q->where('field_office_id', $user->field_office_id ?? 0)),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Leaves out returned attempts that a later submission of the same kind by the same agency has
     * replaced. The monitoring workbook keeps one row per attempt, so without this the old returns
     * would swamp the dashboards; the full list still shows them.
     *
     * @param  Builder<HrMechanism>  $query
     * @return Builder<HrMechanism>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('hr_mechanisms.status', '!=', MechanismStatus::TransmittalCompliance->value)
            ->orWhereNotExists(fn ($later) => $later->selectRaw('1')->from('hr_mechanisms as newer')
                ->whereColumn('newer.agency_id', 'hr_mechanisms.agency_id')
                ->whereColumn('newer.mechanism_type', 'hr_mechanisms.mechanism_type')
                ->where(fn ($w) => $w
                    ->whereColumn('newer.date_submitted', '>', 'hr_mechanisms.date_submitted')
                    ->orWhere(fn ($same) => $same->whereColumn('newer.date_submitted', 'hr_mechanisms.date_submitted')->whereColumn('newer.id', '>', 'hr_mechanisms.id')))));
    }

    /** Records a step in the history of this mechanism. */
    public function recordEvent(string $action, ?User $by, ?MechanismStatus $from, ?MechanismStatus $to, ?string $remarks = null): HrMechanismEvent
    {
        return $this->events()->create([
            'user_id' => $by?->id,
            'action' => $action,
            'from_status' => $from?->value,
            'to_status' => $to?->value,
            'remarks' => $remarks,
        ]);
    }
}
