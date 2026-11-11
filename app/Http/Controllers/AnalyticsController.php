<?php

namespace App\Http\Controllers;

use App\Enums\MechanismType;
use App\Enums\UserRole;
use App\Models\FieldOffice;
use App\Support\MechanismAnalytics;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The visual overview: how many came in, where they stand, how fast they are approved. Read-only, and limited to what the person may see. */
class AnalyticsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $filters = $request->validate([
            'type' => ['nullable', Rule::enum(MechanismType::class)],
            'field_office' => ['nullable', 'integer'],
        ]);

        return Inertia::render('Analytics/Index', [
            'data' => MechanismAnalytics::summary(MechanismAnalytics::query($user, $filters)),
            'filters' => $filters,
            'types' => collect(MechanismType::cases())->map(fn ($t) => ['value' => $t->value, 'label' => $t->label()])->all(),
            'fieldOffices' => $user->role === UserRole::FieldOffice ? [] : FieldOffice::orderBy('name')->get(['id', 'name']),
            'scope' => $user->role === UserRole::FieldOffice ? $user->fieldOffice?->name : 'All field offices',
            'generatedAt' => now()->toIso8601String(),
        ]);
    }
}
