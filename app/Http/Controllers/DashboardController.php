<?php

namespace App\Http\Controllers;

use App\Enums\MechanismStatus;
use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\HrMechanism;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** One dashboard route, two views: the PSED / Director work board, and the read-only Field Office overview. */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $counts = HrMechanism::visibleTo($user)->current()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $base = [
            'counts' => collect(MechanismStatus::cases())->map(fn (MechanismStatus $s) => [
                'status' => $s->value,
                'tone' => $s->tone(),
                'total' => (int) ($counts[$s->value] ?? 0),
            ])->all(),
        ];

        return $user->role === UserRole::FieldOffice
            ? Inertia::render('Dashboard/FieldOffice', [...$base, ...$this->fieldOffice($user)])
            : Inertia::render('Dashboard/Psed', [...$base, ...$this->psed($user)]);
    }

    /** @return array<string, mixed> */
    private function psed(User $user): array
    {
        $column = fn (MechanismStatus $status, string $hint, int $limit) => [
            'status' => $status->value,
            'tone' => $status->tone(),
            'hint' => $hint,
            'items' => HrMechanism::visibleTo($user)->current()->with('agency.fieldOffice', 'actionOfficer', 'officers')
                ->where('status', $status)
                ->when($status->clockRuns(), fn ($q) => $q->orderBy('due_at'), fn ($q) => $q->latest('date_submitted'))
                ->limit($limit)->get()
                ->map(fn (HrMechanism $m) => MechanismPresenter::row($m, $user))->all(),
        ];

        return [
            'columns' => [
                $column(MechanismStatus::Received, 'Waiting to be routed to an Action Officer', 40),
                $column(MechanismStatus::Routed, 'Waiting for the officer to acknowledge', 40),
                $column(MechanismStatus::Processing, 'Being evaluated. The 20-day clock runs', 40),
                $column(MechanismStatus::ForChiefReview, 'Waiting for the PSED Chief', 40),
                $column(MechanismStatus::ReviewedByChief, 'Agency is signing three copies', 40),
                $column(MechanismStatus::ForCompliance, 'Sent back to the agency', 40),
                $column(MechanismStatus::Approved, 'Most recent approvals', 10),
            ],
            // The work waiting on this person, for the strip at the top.
            'mine' => [
                'to_acknowledge' => HrMechanism::where('action_officer_id', $user->id)->where('status', MechanismStatus::Routed)->count(),
                'processing' => HrMechanism::where('action_officer_id', $user->id)->where('status', MechanismStatus::Processing)->count(),
                'overdue' => HrMechanism::where('action_officer_id', $user->id)->whereIn('status', [MechanismStatus::Processing, MechanismStatus::ForChiefReview])->where('due_at', '<', now()->startOfDay())->count(),
                'to_route' => $user->isPsed() ? HrMechanism::visibleTo($user)->where('status', MechanismStatus::Received)->count() : 0,
                'to_review' => $user->isChief() ? HrMechanism::where('status', MechanismStatus::ForChiefReview)->count() : 0,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function fieldOffice(User $user): array
    {
        $agencies = Agency::query()->where('field_office_id', $user->field_office_id ?? 0)->orderBy('name')->get();
        $all = HrMechanism::visibleTo($user)->current()->get()->groupBy('agency_id');

        return [
            'office' => $user->fieldOffice?->name,
            'agencies' => $agencies->map(function (Agency $a) use ($all) {
                $rows = $all->get($a->id, collect());
                $in = fn (MechanismStatus ...$s) => $rows->whereIn('status', $s)->count();

                return [
                    'id' => $a->id,
                    'name' => $a->name,
                    'sector' => $a->sector,
                    'total' => $rows->count(),
                    'received' => $in(MechanismStatus::Received),
                    'in_process' => $in(MechanismStatus::Routed, MechanismStatus::Processing, MechanismStatus::ForChiefReview),
                    'for_compliance' => $in(MechanismStatus::ForCompliance),
                    'reviewed' => $in(MechanismStatus::ReviewedByChief),
                    'approved' => $in(MechanismStatus::Approved),
                    'last_activity' => $rows->max('updated_at')?->toIso8601String(),
                ];
            })->all(),
        ];
    }
}
