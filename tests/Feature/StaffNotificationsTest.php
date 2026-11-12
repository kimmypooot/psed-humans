<?php

namespace Tests\Feature;

use App\Enums\MechanismStatus;
use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\HrMechanism;
use App\Models\User;
use App\Notifications\MechanismActivity;
use App\Support\StaffNotices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** What the bell tells PSED staff, the Chief and the Director, and the page that lists it. */
class StaffNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function person(UserRole $role, string $name = 'Someone'): User
    {
        return User::factory()->role($role)->create(['name' => $name]);
    }

    private function mechanism(MechanismStatus $status, ?User $officer = null, array $attributes = []): HrMechanism
    {
        $agency = Agency::factory()->create(['name' => 'Municipal Government of Javier', 'listed' => true]);

        return HrMechanism::factory()->for($agency)->create(array_merge(
            ['status' => $status, 'mechanism_type' => 'MSP', 'title' => null, 'action_officer_id' => $officer?->id],
            $attributes,
        ));
    }

    private function kinds(User $user): array
    {
        return $user->notifications()->get()->map(fn ($n) => $n->data['kind'])->all();
    }

    public function test_a_new_public_submission_tells_all_psed_staff_and_the_chief_but_not_the_director_or_field_office(): void
    {
        Storage::fake('local');
        $staff = $this->person(UserRole::PsedStaff);
        $chief = $this->person(UserRole::PsedChief);
        $director = $this->person(UserRole::Director);
        $field = $this->person(UserRole::FieldOffice);
        $agency = Agency::factory()->create(['listed' => true]);

        $this->post('/submit', [
            'agency_id' => $agency->id,
            'mechanism_type' => 'MSP',
            'contact_email' => 'hr@agency.gov.ph',
            'document' => UploadedFile::fake()->create('msp.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ])->assertSessionHasNoErrors();

        $this->assertSame(['submitted'], $this->kinds($staff));
        $this->assertSame(['submitted'], $this->kinds($chief));
        $this->assertSame([], $this->kinds($director));
        $this->assertSame([], $this->kinds($field));

        $data = $staff->notifications()->first()->data;
        $this->assertSame('action', $data['category']);
        $this->assertSame('/mechanisms?open='.HrMechanism::firstOrFail()->id, $data['url']);
    }

    public function test_routing_tells_the_officer_but_not_when_they_route_it_to_themselves(): void
    {
        $router = $this->person(UserRole::PsedStaff, 'Router');
        $officer = $this->person(UserRole::PsedStaff, 'Officer');
        $mechanism = $this->mechanism(MechanismStatus::Received);

        $this->actingAs($router)->post("/mechanisms/{$mechanism->id}/route", ['officer_id' => $officer->id])->assertRedirect();

        $this->assertSame(['routed'], $this->kinds($officer));
        $this->assertStringContainsString('Router', $officer->notifications()->first()->data['body']);
        $this->assertSame([], $this->kinds($router));

        $mine = $this->mechanism(MechanismStatus::Received);
        $this->actingAs($router)->post("/mechanisms/{$mine->id}/route", ['officer_id' => $router->id])->assertRedirect();
        $this->assertSame([], $this->kinds($router), 'no notice for what you did yourself');
    }

    public function test_a_corrected_file_goes_back_to_the_officer_or_to_psed_when_nobody_had_it(): void
    {
        $officer = $this->person(UserRole::PsedStaff);
        $other = $this->person(UserRole::PsedStaff);

        StaffNotices::resubmitted($this->mechanism(MechanismStatus::Routed, $officer));
        $this->assertSame(['resubmitted'], $this->kinds($officer));
        $this->assertSame([], $this->kinds($other));

        StaffNotices::resubmitted($this->mechanism(MechanismStatus::Received));
        $this->assertSame(['resubmitted'], $this->kinds($other));
    }

    public function test_the_chiefs_decision_and_the_final_approval_tell_the_officer_never_the_person_who_acted(): void
    {
        $officer = $this->person(UserRole::PsedStaff);
        $chief = $this->person(UserRole::PsedChief, 'Chief');
        $director = $this->person(UserRole::Director);
        $mechanism = $this->mechanism(MechanismStatus::ReviewedByChief, $officer);

        StaffNotices::readyToTransmit($mechanism, $chief, forCompliance: false);
        StaffNotices::returnedToOfficer($mechanism, $chief, 'The resolution is missing.');
        $this->assertEqualsCanonicalizing(['reviewed', 'returned_to_officer'], $this->kinds($officer));
        $this->assertSame([], $this->kinds($chief));

        $signed = $this->mechanism(MechanismStatus::TransmittalApproved, $officer);
        $this->actingAs($director)->post("/mechanisms/{$signed->id}/final-approve")->assertRedirect();

        $this->assertContains('approved', $this->kinds($officer));
        $this->assertSame(['approved'], $this->kinds($chief), 'the Chief hears of the final approval too');
        $this->assertSame([], $this->kinds($director));
    }

    public function test_the_reminder_command_warns_once_when_due_soon_and_again_once_when_overdue(): void
    {
        $officer = $this->person(UserRole::PsedStaff);
        $chief = $this->person(UserRole::PsedChief);
        $soon = $this->mechanism(MechanismStatus::Processing, $officer, ['due_at' => now()->addWeekdays(2), 'acknowledged_at' => now()->subDays(25)]);
        $this->mechanism(MechanismStatus::Processing, $officer, ['due_at' => now()->addWeekdays(10), 'acknowledged_at' => now()->subDays(5)]); // not yet

        $this->artisan('mechanisms:remind-due')->expectsOutput('Sent 1 reminder.')->assertSuccessful();
        $this->artisan('mechanisms:remind-due')->expectsOutput('Sent 0 reminders.')->assertSuccessful();
        $this->assertSame(['due_soon'], $this->kinds($officer));
        $this->assertSame([], $this->kinds($chief), 'the Chief only hears about the overdue ones');

        $soon->update(['due_at' => now()->subWeekdays(2)]);
        $this->artisan('mechanisms:remind-due')->assertSuccessful();
        $this->artisan('mechanisms:remind-due')->expectsOutput('Sent 0 reminders.')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['due_soon', 'overdue'], $this->kinds($officer));
        $this->assertSame(['overdue'], $this->kinds($chief));
    }

    public function test_a_mechanism_with_no_deadline_or_already_done_is_never_reminded(): void
    {
        $officer = $this->person(UserRole::PsedStaff);
        $this->mechanism(MechanismStatus::Received, $officer, ['due_at' => null]);
        $this->mechanism(MechanismStatus::Approved, $officer, ['due_at' => now()->subDays(40)]);
        $this->mechanism(MechanismStatus::TransmittalCompliance, $officer, ['due_at' => now()->subDays(40)]);

        $this->artisan('mechanisms:remind-due')->expectsOutput('Sent 0 reminders.')->assertSuccessful();
        $this->assertSame([], $this->kinds($officer));
    }

    public function test_the_page_lists_only_my_notifications_newest_first_and_filters_them(): void
    {
        $me = $this->person(UserRole::PsedStaff);
        $other = $this->person(UserRole::PsedStaff);
        $m = $this->mechanism(MechanismStatus::Received);

        $me->notify(new MechanismActivity($m, 'submitted', 'First', 'a'));
        $me->notify(new MechanismActivity($m, 'overdue', 'Second', 'b'));
        $other->notify(new MechanismActivity($m, 'submitted', 'Not mine', 'c'));
        $first = $me->notifications()->where('data', 'like', '%"First"%')->first();
        $first->forceFill(['created_at' => now()->subMinute()])->save();
        $first->markAsRead();

        $this->actingAs($me)->get('/notifications/all')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Notifications/Index')
            ->where('items.total', 2)
            ->where('unread', 1)
            ->where('items.data.0.kind', 'overdue')
            ->where('items.data.0.tone', 'danger'));

        $this->actingAs($me)->get('/notifications/all?filter=unread')->assertInertia(fn ($page) => $page->where('items.total', 1)->where('items.data.0.title', 'Second'));
        $this->actingAs($me)->get('/notifications/all?category=deadline')->assertInertia(fn ($page) => $page->where('items.total', 1)->where('items.data.0.kind', 'overdue'));
        $this->actingAs($me)->get('/notifications/all?category=nope')->assertSessionHasErrors('category');
        auth()->logout();
        $this->get('/notifications/all')->assertRedirect('/login');
    }

    public function test_the_bell_shows_the_latest_with_kind_and_tone_and_the_unread_count(): void
    {
        $me = $this->person(UserRole::PsedStaff);
        $me->notify(new MechanismActivity($this->mechanism(MechanismStatus::Received), 'routed', 'Routed to you', 'x'));

        $this->actingAs($me)->getJson('/notifications')->assertOk()->assertJson(['unread' => 1, 'items' => [['kind' => 'routed', 'category' => 'action', 'tone' => 'info', 'read' => false]]]);
    }

    public function test_marking_read_and_clearing_only_touch_my_own(): void
    {
        $me = $this->person(UserRole::PsedStaff);
        $other = $this->person(UserRole::PsedStaff);
        $m = $this->mechanism(MechanismStatus::Received);
        $me->notify(new MechanismActivity($m, 'submitted', 'One', 'a'));
        $me->notify(new MechanismActivity($m, 'submitted', 'Two', 'b'));
        $other->notify(new MechanismActivity($m, 'submitted', 'Theirs', 'c'));
        $mine = $me->notifications()->first();

        $this->actingAs($other)->postJson("/notifications/{$mine->id}/read")->assertNotFound();

        $this->actingAs($me)->postJson("/notifications/{$mine->id}/read")->assertJson(['unread' => 1]);
        $this->actingAs($me)->postJson('/notifications/clear-read')->assertJson(['unread' => 1]);
        $this->assertSame(1, $me->notifications()->count());
        $this->assertSame(1, $other->notifications()->count());

        $this->actingAs($me)->postJson('/notifications/read-all')->assertJson(['unread' => 0]);
    }

    public function test_the_sidebar_links_to_the_notifications_page(): void
    {
        $this->actingAs($this->person(UserRole::PsedStaff))->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('navigation', fn ($nav) => collect($nav)->pluck('href')->contains('/notifications/all')));
    }
}
