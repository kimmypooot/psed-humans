<?php

namespace Tests\Feature;

use App\Enums\MechanismStatus;
use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\HrMechanism;
use App\Models\User;
use App\Notifications\MechanismForwardedToChief;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChiefNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function processing(User $officer): HrMechanism
    {
        $agency = Agency::factory()->create(['name' => 'Municipal Government of Mayorga, Leyte', 'listed' => true]);

        return HrMechanism::factory()->for($agency)->create(['status' => MechanismStatus::Processing, 'action_officer_id' => $officer->id, 'title' => 'MSP']);
    }

    private function forward(User $officer, HrMechanism $mechanism)
    {
        return $this->actingAs($officer)->post("/mechanisms/{$mechanism->id}/forward", [
            'reviewed' => UploadedFile::fake()->create('2026 MSP_LGU Mayorga.docx', 20),
            'checklist' => UploadedFile::fake()->create('Checklist.pdf', 20),
        ]);
    }

    public function test_forwarding_notifies_every_chief_in_the_bell_and_by_email_and_nobody_else(): void
    {
        Storage::fake('local');
        Notification::fake();
        $officer = User::factory()->role(UserRole::PsedStaff)->create(['name' => 'Kim Banoyo']);
        $chief = User::factory()->role(UserRole::PsedChief)->create(['email' => 'chief@csc.gov.ph']);
        $otherChief = User::factory()->role(UserRole::PsedChief)->create(['email' => null]);
        $mechanism = $this->processing($officer);

        $this->forward($officer, $mechanism)->assertRedirect();

        Notification::assertSentTo($chief, MechanismForwardedToChief::class, fn ($n, $channels) => $channels === ['database', 'mail'] && $n->mechanism->is($mechanism));
        Notification::assertSentTo($otherChief, MechanismForwardedToChief::class, fn ($n, $channels) => $channels === ['database'], );
        Notification::assertNotSentTo($officer, MechanismForwardedToChief::class);
        Notification::assertCount(2);
    }

    public function test_the_notice_says_what_is_waiting_and_links_to_it(): void
    {
        Storage::fake('local');
        $officer = User::factory()->role(UserRole::PsedStaff)->create(['name' => 'Kim Banoyo']);
        $chief = User::factory()->role(UserRole::PsedChief)->create(['email' => 'chief@csc.gov.ph']);
        $mechanism = $this->processing($officer);

        $this->forward($officer, $mechanism)->assertRedirect();

        $notice = $chief->notifications()->first();
        $this->assertSame('For your review', $notice->data['title']);
        $this->assertSame('/mechanisms?open='.$mechanism->id, $notice->data['url']);
        $this->assertStringContainsString('Municipal Government of Mayorga, Leyte', $notice->data['body']);
        $this->assertStringContainsString('Kim Banoyo', $notice->data['body']);

        $mail = (new MechanismForwardedToChief($mechanism->fresh(), $officer))->toMail($chief);
        $this->assertStringContainsString('For your review: MSP ('.$mechanism->tracking_code.')', $mail->subject);
        $text = implode(' ', array_map(fn ($l) => is_string($l) ? $l : '', $mail->introLines));
        $this->assertStringContainsString('Kim Banoyo forwarded a reviewed HR mechanism to you.', $text);
        $this->assertStringContainsString('2026 MSP_LGU Mayorga.docx', $text);
        $this->assertStringContainsString('Checklist.pdf', $text);
        $this->assertSame(url('/mechanisms?open='.$mechanism->id), $mail->actionUrl);
        $this->assertEmpty($mail->attachments, 'the documents are opened in the system, not emailed');
    }

    public function test_a_mail_server_that_is_down_does_not_stop_the_forwarding_or_the_bell(): void
    {
        Storage::fake('local');
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
        $officer = User::factory()->role(UserRole::PsedStaff)->create();
        $chief = User::factory()->role(UserRole::PsedChief)->create(['email' => 'chief@csc.gov.ph']);
        $mechanism = $this->processing($officer);

        $this->forward($officer, $mechanism)->assertRedirect();

        $this->assertSame(MechanismStatus::ForChiefReview, $mechanism->fresh()->status);
        $this->assertCount(1, $chief->notifications, 'the in-app notice is kept');
    }

    public function test_the_bell_lists_the_persons_own_notices_and_marks_them_read(): void
    {
        $chief = User::factory()->role(UserRole::PsedChief)->create();
        $other = User::factory()->role(UserRole::PsedChief)->create();
        $mechanism = HrMechanism::factory()->create(['status' => MechanismStatus::ForChiefReview]);
        $chief->notify(new MechanismForwardedToChief($mechanism));
        $chief->notify(new MechanismForwardedToChief($mechanism));
        $other->notify(new MechanismForwardedToChief($mechanism));

        $list = $this->actingAs($chief)->getJson('/notifications')->assertOk()->assertJsonPath('unread', 2)->assertJsonCount(2, 'items');
        $id = $list->json('items.0.id');

        $this->actingAs($chief)->postJson("/notifications/{$id}/read")->assertOk()->assertJsonPath('unread', 1);
        $this->actingAs($chief)->getJson('/notifications')->assertJsonPath('unread', 1);

        // Someone else's notice is not theirs to read.
        $theirs = $other->notifications()->first()->id;
        $this->actingAs($chief)->postJson("/notifications/{$theirs}/read")->assertNotFound();
        $this->assertNull($other->notifications()->first()->read_at);

        $this->actingAs($chief)->postJson('/notifications/read-all')->assertOk()->assertJsonPath('unread', 0);
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    public function test_the_bell_needs_a_signed_in_person(): void
    {
        $this->getJson('/notifications')->assertUnauthorized();
    }

    public function test_the_notice_link_opens_that_mechanism_over_the_list(): void
    {
        $chief = User::factory()->role(UserRole::PsedChief)->create();
        $mechanism = HrMechanism::factory()->create(['status' => MechanismStatus::ForChiefReview]);

        $this->actingAs($chief)->get("/mechanisms?open={$mechanism->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Mechanisms/Index')->where('filters.open', (string) $mechanism->id));
    }
}
