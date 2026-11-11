<?php

namespace Tests\Feature;

use App\Enums\MechanismStatus;
use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\FieldOffice;
use App\Models\HrMechanism;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsAndReportsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(UserRole $role = UserRole::PsedStaff, ?FieldOffice $office = null): User
    {
        return User::factory()->role($role)->create(['field_office_id' => $office?->id]);
    }

    /** @param array<string, mixed> $attributes */
    private function mechanism(Agency $agency, MechanismStatus $status, array $attributes = []): HrMechanism
    {
        return HrMechanism::factory()->for($agency)->create(array_merge(['status' => $status, 'mechanism_type' => 'MSP', 'date_submitted' => now()->subDays(30)], $attributes));
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get('/analytics')->assertRedirect('/login');
        $this->get('/reports')->assertRedirect('/login');
        $this->get('/reports/export')->assertRedirect('/login');
    }

    public function test_the_sidebar_links_to_both_pages(): void
    {
        $this->actingAs($this->staff())->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('navigation', fn ($nav) => collect($nav)->pluck('href')->filter()->contains('/analytics') && collect($nav)->pluck('href')->filter()->contains('/reports')));
    }

    public function test_analytics_counts_status_approval_rate_and_time_to_approval(): void
    {
        $agency = Agency::factory()->create();
        $this->mechanism($agency, MechanismStatus::Approved, ['date_submitted' => now()->subDays(40), 'date_approved' => now()->subDays(10)]); // 30 days
        $this->mechanism($agency, MechanismStatus::Approved, ['mechanism_type' => 'RSP', 'date_submitted' => now()->subDays(20), 'date_approved' => now()->subDays(10)]); // 10 days
        $this->mechanism($agency, MechanismStatus::Processing, ['mechanism_type' => 'SPMS', 'due_at' => now()->subDays(3)]);
        $this->mechanism($agency, MechanismStatus::Received, ['mechanism_type' => 'GM']);

        $this->actingAs($this->staff())->get('/analytics')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Analytics/Index')
            ->where('data.kpis.total', 4)
            ->where('data.kpis.approved', 2)
            ->where('data.kpis.approval_rate', 50)
            ->where('data.kpis.in_process', 2)
            ->where('data.kpis.overdue', 1)
            ->where('data.kpis.median_days_to_approval', 20)
            ->where('data.kpis.avg_days_to_approval', 20)
            ->where('data.statuses.0.total', 1) // Received
            ->has('data.trend', 12)
            ->where('data.turnaround.0.total', 1) // within 20 days
            ->where('data.turnaround.1.total', 1)); // 21-40 days
    }

    public function test_analytics_filters_by_kind(): void
    {
        $agency = Agency::factory()->create();
        $this->mechanism($agency, MechanismStatus::Received);
        $this->mechanism($agency, MechanismStatus::Received, ['mechanism_type' => 'RSP']);

        $this->actingAs($this->staff())->get('/analytics?type=RSP')->assertInertia(fn ($page) => $page->where('data.kpis.total', 1));
        $this->actingAs($this->staff())->get('/analytics?type=NOPE')->assertSessionHasErrors('type');
    }

    public function test_analytics_is_empty_but_fine_with_no_mechanisms(): void
    {
        $this->actingAs($this->staff())->get('/analytics')->assertOk()->assertInertia(fn ($page) => $page
            ->where('data.kpis.total', 0)
            ->where('data.kpis.approval_rate', 0)
            ->where('data.kpis.median_days_to_approval', null));
    }

    public function test_a_field_office_only_sees_its_own_agencies_in_analytics_and_reports(): void
    {
        $mine = FieldOffice::factory()->create();
        $other = FieldOffice::factory()->create();
        $this->mechanism(Agency::factory()->create(['field_office_id' => $mine->id]), MechanismStatus::Received);
        $this->mechanism(Agency::factory()->create(['field_office_id' => $other->id]), MechanismStatus::Received);
        $this->mechanism(Agency::factory()->create(['field_office_id' => $other->id]), MechanismStatus::Approved);
        $user = $this->staff(UserRole::FieldOffice, $mine);

        $this->actingAs($user)->get('/analytics')->assertInertia(fn ($page) => $page
            ->where('data.kpis.total', 1)
            ->where('fieldOffices', []));
        $this->actingAs($user)->get('/reports')->assertInertia(fn ($page) => $page
            ->where('report.totals.all', 1)
            ->where('rows.total', 1));
        // Asking for another office's agencies does not widen it.
        $this->actingAs($user)->get('/reports?field_office='.$other->id)->assertInertia(fn ($page) => $page->where('rows.total', 0));
    }

    public function test_the_report_counts_by_kind_and_status_and_filters_by_period(): void
    {
        $agency = Agency::factory()->create();
        $this->mechanism($agency, MechanismStatus::Approved, ['date_submitted' => '2026-02-10']);
        $this->mechanism($agency, MechanismStatus::Received, ['date_submitted' => '2026-02-20']);
        $this->mechanism($agency, MechanismStatus::Received, ['mechanism_type' => 'RSP', 'date_submitted' => '2026-05-05']);

        $this->actingAs($this->staff())->get('/reports')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Reports/Index')
            ->where('report.totals.all', 3)
            ->where('report.totals.Approved', 1)
            ->where('report.matrix.0.label', 'MSP')
            ->where('report.matrix.0.total', 2));

        $this->actingAs($this->staff())->get('/reports?from=2026-02-01&to=2026-02-28')->assertInertia(fn ($page) => $page
            ->where('report.totals.all', 2)
            ->where('rows.total', 2));

        $this->actingAs($this->staff())->get('/reports?status=Approved')->assertInertia(fn ($page) => $page->where('rows.total', 1));
        $this->actingAs($this->staff())->get('/reports?from=2026-03-01&to=2026-02-01')->assertSessionHasErrors('to');
    }

    public function test_the_csv_export_has_the_filtered_rows_and_is_recorded(): void
    {
        $agency = Agency::factory()->create(['name' => 'Municipal Government of Javier']);
        $this->mechanism($agency, MechanismStatus::Approved, ['tracking_code' => 'HRM-2026-00007', 'date_submitted' => '2026-02-10', 'date_approved' => '2026-03-01']);
        $this->mechanism($agency, MechanismStatus::Received, ['mechanism_type' => 'RSP', 'tracking_code' => 'HRM-2026-00008']);

        $response = $this->actingAs($this->staff())->get('/reports/export?status=Approved');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('hr-mechanisms-report-', $response->headers->get('Content-Disposition'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Tracking code', $csv);
        $this->assertStringContainsString('HRM-2026-00007', $csv);
        $this->assertStringContainsString('Municipal Government of Javier', $csv);
        $this->assertStringContainsString('2026-03-01', $csv);
        $this->assertStringNotContainsString('HRM-2026-00008', $csv);
        $this->assertSame(1, AuditLog::where('action', 'report.exported')->count());
    }

    public function test_the_csv_export_neutralises_spreadsheet_formulas(): void
    {
        $agency = Agency::factory()->create(['name' => '=HYPERLINK("http://evil.test")']);
        $this->mechanism($agency, MechanismStatus::Received);

        $csv = $this->actingAs($this->staff())->get('/reports/export')->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(',=HYPERLINK', $csv);
        $this->assertStringNotContainsString('"=HYPERLINK', $csv);
    }
}
