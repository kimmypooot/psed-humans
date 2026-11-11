<?php

namespace App\Http\Controllers;

use App\Enums\MechanismStatus;
use App\Enums\MechanismType;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Models\HrMechanism;
use App\Support\MechanismAnalytics;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A printable report of HR mechanisms for a period, with a CSV export. Limited to what the person may see. */
class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $this->filters($request);
        $query = MechanismAnalytics::query($user, $filters);

        $rows = (clone $query)->with('agency.fieldOffice')->orderByDesc('date_submitted')->orderByDesc('hr_mechanisms.id')
            ->paginate(20)->withQueryString()
            ->through(fn (HrMechanism $m) => [
                'id' => $m->id,
                'tracking_code' => $m->tracking_code,
                'agency' => $m->agency?->name,
                'field_office' => $m->agency?->fieldOffice?->name,
                'label' => MechanismPresenter::label($m),
                'status' => $m->status->value,
                'tone' => $m->status->tone(),
                'date_submitted' => $m->date_submitted?->toIso8601String(),
                'date_approved' => $m->date_approved?->toIso8601String(),
            ]);

        return Inertia::render('Reports/Index', [
            'rows' => $rows,
            'report' => MechanismAnalytics::matrix($query),
            'filters' => $filters,
            'statuses' => collect(MechanismStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->value])->all(),
            'types' => collect(MechanismType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()])->all(),
            'fieldOffices' => $user->role === UserRole::FieldOffice ? [] : FieldOffice::orderBy('name')->get(['id', 'name']),
            'scope' => $user->role === UserRole::FieldOffice ? $user->fieldOffice?->name : 'All field offices',
            'preparedBy' => ['name' => $user->name, 'role' => $user->role->label()],
            'generatedAt' => now()->toIso8601String(),
        ]);
    }

    /** The same rows as the report, as a spreadsheet-friendly CSV. The export is recorded. */
    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();
        $filters = $this->filters($request);
        $query = MechanismAnalytics::query($user, $filters)->with('agency.fieldOffice')->orderByDesc('date_submitted')->orderByDesc('hr_mechanisms.id');

        AuditLog::record('report.exported', null, after: array_filter($filters));

        $name = 'hr-mechanisms-report-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads the accents
            fputcsv($out, ['Tracking code', 'Agency', 'Field office', 'HR mechanism', 'Status', 'Date submitted', 'Date approved']);

            $query->chunk(500, function ($chunk) use ($out) {
                foreach ($chunk as $m) {
                    fputcsv($out, array_map(self::safe(...), [
                        $m->tracking_code,
                        $m->agency?->name,
                        $m->agency?->fieldOffice?->name,
                        MechanismPresenter::label($m),
                        $m->status->value,
                        $m->date_submitted?->format('Y-m-d'),
                        $m->date_approved?->format('Y-m-d'),
                    ]));
                }
            });

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** A cell that starts with =, +, - or @ would run as a formula in Excel: prefix it so it stays text. */
    private static function safe(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'status' => ['nullable', Rule::enum(MechanismStatus::class)],
            'type' => ['nullable', Rule::enum(MechanismType::class)],
            'field_office' => ['nullable', 'integer'],
        ]);
    }
}
