<?php

namespace App\Support;

use App\Enums\MechanismStatus;
use App\Enums\MechanismType;
use App\Models\HrMechanism;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The numbers behind the Analytics and Reports pages. It only ever starts from what the signed-in
 * person may see (HrMechanism::visibleTo), and counts the current attempt of each mechanism, the way
 * the dashboards do. The totals are worked out in PHP from a few columns so they read the same on
 * MySQL and SQLite.
 */
class MechanismAnalytics
{
    /**
     * The mechanisms in scope: what the user may see, narrowed by the filters.
     *
     * @param  array<string, mixed>  $filters  from, to, type, status, field_office
     * @return Builder<HrMechanism>
     */
    public static function query(User $user, array $filters): Builder
    {
        return HrMechanism::visibleTo($user)->current()
            ->when($filters['from'] ?? null, fn (Builder $q, $v) => $q->where('hr_mechanisms.date_submitted', '>=', CarbonImmutable::parse($v)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $q, $v) => $q->where('hr_mechanisms.date_submitted', '<=', CarbonImmutable::parse($v)->endOfDay()))
            ->when($filters['type'] ?? null, fn (Builder $q, $v) => $q->where('hr_mechanisms.mechanism_type', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, $v) => $q->where('hr_mechanisms.status', $v))
            ->when($filters['field_office'] ?? null, fn (Builder $q, $v) => $q->whereHas('agency', fn ($a) => $a->where('field_office_id', $v)));
    }

    /**
     * Everything the Analytics page draws.
     *
     * @param  Builder<HrMechanism>  $query
     * @return array<string, mixed>
     */
    public static function summary(Builder $query, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $rows = $query->with('agency.fieldOffice')->get();

        $total = $rows->count();
        $approved = $rows->where('status', MechanismStatus::Approved);
        $days = $approved
            ->filter(fn (HrMechanism $m) => $m->date_submitted && $m->date_approved && $m->date_approved >= $m->date_submitted)
            ->map(fn (HrMechanism $m) => (int) $m->date_submitted->startOfDay()->diffInDays($m->date_approved->startOfDay()))
            ->values();

        $overdue = $rows->filter(fn (HrMechanism $m) => $m->status->clockRuns() && $m->due_at && $m->due_at->lt($now->startOfDay()))->count();

        $lastMonthStart = $now->startOfMonth()->subMonthNoOverflow();
        $thisMonth = $rows->filter(fn (HrMechanism $m) => $m->date_submitted && $m->date_submitted->isSameMonth($now))->count();
        $lastMonth = $rows->filter(fn (HrMechanism $m) => $m->date_submitted && $m->date_submitted->isSameMonth($lastMonthStart))->count();

        return [
            'kpis' => [
                'total' => $total,
                'approved' => $approved->count(),
                'approval_rate' => $total > 0 ? round($approved->count() / $total * 100, 1) : 0.0,
                'in_process' => $rows->filter(fn (HrMechanism $m) => ! in_array($m->status, [MechanismStatus::Approved, MechanismStatus::TransmittalCompliance], true))->count(),
                'for_compliance' => $rows->where('status', MechanismStatus::TransmittalCompliance)->count(),
                'overdue' => $overdue,
                'avg_days_to_approval' => $days->isNotEmpty() ? round($days->avg(), 1) : null,
                'median_days_to_approval' => $days->isNotEmpty() ? self::median($days) : null,
                'submitted_this_month' => $thisMonth,
                'submitted_last_month' => $lastMonth,
            ],
            'trend' => self::trend($rows, $now),
            'statuses' => collect(MechanismStatus::cases())->map(fn (MechanismStatus $s) => [
                'label' => $s->value,
                'tone' => $s->tone(),
                'total' => $rows->where('status', $s)->count(),
            ])->all(),
            'types' => collect(MechanismType::cases())->map(fn (MechanismType $t) => [
                'label' => $t->value,
                'name' => $t->label(),
                'total' => $rows->where('mechanism_type', $t)->count(),
                'approved' => $rows->where('mechanism_type', $t)->where('status', MechanismStatus::Approved)->count(),
            ])->filter(fn (array $t) => $t['total'] > 0)->sortByDesc('total')->values()->all(),
            'offices' => self::grouped($rows, fn (HrMechanism $m) => $m->agency?->fieldOffice?->name ?? 'No field office', 10),
            'agencies' => self::grouped($rows, fn (HrMechanism $m) => $m->agency?->name ?? 'Unknown agency', 8),
            'turnaround' => self::buckets($days),
        ];
    }

    /**
     * The report: counts by kind and status, for the table above the list.
     *
     * @param  Builder<HrMechanism>  $query
     * @return array{matrix: list<array<string, mixed>>, statuses: list<string>, totals: array<string, int>}
     */
    public static function matrix(Builder $query): array
    {
        $rows = (clone $query)->reorder()
            ->selectRaw('hr_mechanisms.mechanism_type as kind, hr_mechanisms.status as state, count(*) as total')
            ->groupBy('hr_mechanisms.mechanism_type', 'hr_mechanisms.status')
            ->get();
        $statuses = array_map(fn (MechanismStatus $s) => $s->value, MechanismStatus::cases());

        $matrix = collect(MechanismType::cases())->map(function (MechanismType $t) use ($rows, $statuses) {
            $mine = $rows->filter(fn ($r) => $r->getRawOriginal('kind') === $t->value);
            $cells = [];

            foreach ($statuses as $s) {
                $cells[$s] = (int) $mine->filter(fn ($r) => $r->getRawOriginal('state') === $s)->sum(fn ($r) => (int) $r->getRawOriginal('total'));
            }

            return ['label' => $t->value, 'name' => $t->label(), 'cells' => $cells, 'total' => array_sum($cells)];
        })->filter(fn (array $r) => $r['total'] > 0)->values()->all();

        $totals = array_fill_keys($statuses, 0);

        foreach ($matrix as $r) {
            foreach ($r['cells'] as $s => $n) {
                $totals[$s] += $n;
            }
        }

        return ['matrix' => $matrix, 'statuses' => $statuses, 'totals' => $totals + ['all' => array_sum($totals)]];
    }

    /**
     * Twelve months ending this month: what came in and what was approved.
     *
     * @param  Collection<int, HrMechanism>  $rows
     * @return list<array{label: string, month: string, submitted: int, approved: int}>
     */
    private static function trend(Collection $rows, CarbonImmutable $now): array
    {
        $out = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = $now->startOfMonth()->subMonthsNoOverflow($i);
            $out[] = [
                'label' => $month->format('M'),
                'month' => $month->format('M Y'),
                'submitted' => $rows->filter(fn (HrMechanism $m) => $m->date_submitted?->isSameMonth($month))->count(),
                'approved' => $rows->filter(fn (HrMechanism $m) => $m->status === MechanismStatus::Approved && $m->date_approved?->isSameMonth($month))->count(),
            ];
        }

        return $out;
    }

    /**
     * @param  Collection<int, HrMechanism>  $rows
     * @return list<array{label: string, total: int, approved: int}>
     */
    private static function grouped(Collection $rows, callable $key, int $limit): array
    {
        return $rows->groupBy($key)->map(fn (Collection $g, string $label) => [
            'label' => $label,
            'total' => $g->count(),
            'approved' => $g->where('status', MechanismStatus::Approved)->count(),
        ])->sortByDesc('total')->take($limit)->values()->all();
    }

    /**
     * How long approved mechanisms took, in calendar days from submission to approval.
     *
     * @param  Collection<int, int>  $days
     * @return list<array{label: string, total: int}>
     */
    private static function buckets(Collection $days): array
    {
        $bands = [['Within 20 days', 0, 20], ['21–40 days', 21, 40], ['41–60 days', 41, 60], ['61–90 days', 61, 90], ['Over 90 days', 91, PHP_INT_MAX]];

        return array_map(fn (array $b) => ['label' => $b[0], 'total' => $days->filter(fn (int $d) => $d >= $b[1] && $d <= $b[2])->count()], $bands);
    }

    /** @param  Collection<int, int>  $values */
    private static function median(Collection $values): float
    {
        $sorted = $values->sort()->values();
        $mid = intdiv($sorted->count(), 2);

        return (float) ($sorted->count() % 2 ? $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2);
    }
}
