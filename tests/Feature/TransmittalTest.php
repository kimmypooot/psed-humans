<?php

namespace Tests\Feature;

use App\Enums\MechanismStatus;
use App\Enums\MechanismType;
use App\Enums\UserRole;
use App\Models\Agency;
use App\Models\HrMechanism;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\SignsPdfs;
use Tests\TestCase;
use ZipArchive;

class TransmittalTest extends TestCase
{
    use RefreshDatabase;
    use SignsPdfs;

    /** A Chief's decision, with the HR checklist signed by that Chief with a PNPKI certificate. */
    private function decision(User $chief, array $payload): array
    {
        return $payload + ['signed_checklist' => $this->signedPdf($chief->name)];
    }

    private function imis(): void
    {
        config(['services.imis.api_url' => 'http://imis.test', 'services.imis.api_token' => 'secret']);
        Cache::forget('imis.directory');
        Cache::forget('imis.directory.last_good');
        Http::fake(['imis.test/*' => Http::response(['data' => [
            ['id' => 50, 'honorific' => 'Atty.', 'first_name' => 'Marilyn', 'middle_name' => 'Eclipse', 'last_name' => 'Taldo', 'name_extension' => null, 'position' => 'Director IV'],
            ['id' => 66, 'honorific' => null, 'first_name' => 'Gina', 'middle_name' => 'Abellana', 'last_name' => 'Crucio', 'name_extension' => null, 'position' => 'Director III'],
            ['id' => 12, 'honorific' => null, 'first_name' => 'Kim Benedick', 'middle_name' => 'Macawile', 'last_name' => 'Banoyo', 'name_extension' => null, 'position' => 'Human Resource Specialist I'],
        ]])]);
    }

    /** @return array<string, mixed> */
    private function fields(array $override = []): array
    {
        return $override + [
            'date' => '2027-03-09',
            'addressee_name' => 'Hon. Michael Dragon T. Javier',
            'addressee_position' => 'Municipal Mayor',
            'addressee_address' => "Municipal Government of Javier\nProvince of Leyte",
            'salutation' => 'Mayor Javier',
            'subject' => '2027 Revised Merit Selection Plan (MSP)',
            'signatory_id' => 66,
        ];
    }

    private function forReview(string $agencyName = 'Municipal Government of Javier, Leyte'): HrMechanism
    {
        $agency = Agency::factory()->create(['name' => $agencyName, 'listed' => true]);
        $mechanism = HrMechanism::factory()->for($agency)->create(['status' => MechanismStatus::ReviewedByChief, 'mechanism_type' => MechanismType::Msp, 'title' => 'Revised MSP']);
        foreach (['reviewed', 'checklist'] as $kind) {
            $mechanism->attachments()->create(['kind' => $kind, 'path' => "x/{$kind}.pdf", 'name' => "{$kind}.pdf"]);
        }

        return $mechanism;
    }

    /** The text of the word/document.xml of the stored transmittal. */
    private function letterText(HrMechanism $mechanism): string
    {
        $file = $mechanism->fresh()->currentAttachments()->firstWhere('kind', 'transmittal');
        $path = tempnam(sys_get_temp_dir(), 'tt');
        file_put_contents($path, Storage::disk('local')->get($file->path));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'the transmittal is a valid .docx');
        $xml = (string) $zip->getFromName('word/document.xml');
        $this->assertNotFalse($zip->getFromName('word/header1.xml'), 'the letterhead is kept');
        $zip->close();
        @unlink($path);

        return html_entity_decode(strip_tags(preg_replace('#</w:p>#', "\n", $xml)), ENT_QUOTES | ENT_XML1);
    }

    public function test_approving_writes_the_for_approval_letter_signed_by_the_director_chosen(): void
    {
        Storage::fake('local');
        Notification::fake();
        $this->imis();
        $mechanism = $this->forReview();

        $chief = User::factory()->role(UserRole::PsedChief)->create();
        $this->actingAs($chief)
            ->post("/mechanisms/{$mechanism->id}/chief-approve", $this->decision($chief, ['transmittal' => $this->fields(['signatory_id' => 50])]))->assertRedirect();

        $text = $this->letterText($mechanism);
        $this->assertStringContainsString('9 March 2027', $text);
        $this->assertStringContainsString('HON. MICHAEL DRAGON T. JAVIER', $text);
        $this->assertStringContainsString('Municipal Mayor', $text);
        $this->assertStringContainsString('Province of Leyte', $text);
        $this->assertStringContainsString('Dear Mayor Javier:', $text);
        $this->assertStringContainsString('2027 Revised Merit Selection Plan (MSP), which was primarily anchored on the 2025 Omnibus Rules', $text);
        $this->assertStringContainsString('is already recommended for approval', $text);
        $this->assertStringContainsString('three (3) printed copies', $text);
        $this->assertStringContainsString('ATTY. MARILYN E. TALDO', $text);
        $this->assertStringContainsString('Director IV', $text);
        $this->assertStringNotContainsString('CRUCIO', $text, 'the template\'s signatory is replaced');
        $this->assertSame('Transmittal_ForApproval_'.$mechanism->tracking_code.'.docx', $mechanism->fresh()->currentAttachments()->firstWhere('kind', 'transmittal')->name);
    }

    public function test_sending_back_writes_the_for_compliance_letter(): void
    {
        Storage::fake('local');
        Notification::fake();
        $this->imis();
        $mechanism = $this->forReview();

        $chief = User::factory()->role(UserRole::PsedChief)->create();
        $this->actingAs($chief)
            ->post("/mechanisms/{$mechanism->id}/compliance", $this->decision($chief, ['remarks' => 'The resolution is missing.', 'transmittal' => $this->fields()]))->assertRedirect();

        $text = $this->letterText($mechanism);
        $this->assertStringContainsString('submitted to this office for further evaluation', $text);
        $this->assertStringContainsString('necessary review of said MSP', $text);
        $this->assertStringContainsString('Checklist for Reviewing Human Resource Mechanisms of Government Agencies: MSP for Local Government Unit (LGU)', $text);
        $this->assertStringContainsString('ATTY. GINA A. CRUCIO', $text);
        $this->assertStringNotContainsString('recommended for approval', $text);
        $this->assertSame('Transmittal_ForCompliance_'.$mechanism->tracking_code.'.docx', $mechanism->fresh()->currentAttachments()->firstWhere('kind', 'transmittal')->name);
    }

    public function test_only_the_two_directors_can_sign_and_the_letter_needs_its_fields(): void
    {
        Storage::fake('local');
        Notification::fake();
        $this->imis();
        $mechanism = $this->forReview();
        $chief = User::factory()->role(UserRole::PsedChief)->create();

        // 12 is an employee, not the Director IV or the Director III.
        $this->actingAs($chief)->post("/mechanisms/{$mechanism->id}/chief-approve", $this->decision($chief, ['transmittal' => $this->fields(['signatory_id' => 12])]))->assertSessionHasErrors('transmittal.signatory_id');
        $this->actingAs($chief)->post("/mechanisms/{$mechanism->id}/chief-approve", $this->decision($chief, ['transmittal' => $this->fields(['addressee_name' => '', 'subject' => ''])]))->assertSessionHasErrors(['transmittal.addressee_name', 'transmittal.subject']);

        $this->assertSame(MechanismStatus::ReviewedByChief, $mechanism->fresh()->status, 'nothing happened');
        $this->assertNull($mechanism->fresh()->currentAttachments()->firstWhere('kind', 'transmittal'));
    }

    public function test_the_next_letter_to_the_same_agency_starts_with_the_head_written_to_before(): void
    {
        Storage::fake('local');
        Notification::fake();
        $this->imis();
        $mechanism = $this->forReview();
        $chief = User::factory()->role(UserRole::PsedChief)->create();

        $first = $this->actingAs($chief)->getJson("/mechanisms/{$mechanism->id}/transmittal/approval")->assertOk();
        $first->assertJsonPath('defaults.addressee_name', 'HON. ')
            ->assertJsonPath('defaults.addressee_position', 'Municipal Mayor')
            ->assertJsonPath('defaults.addressee_address', "Municipal Government of Javier\nProvince of Leyte")
            ->assertJsonPath('defaults.signatory_id', '66')
            ->assertJsonPath('signatories.0.label', 'Taldo, Marilyn E. — Director IV')
            ->assertJsonPath('signatories.1.label', 'Crucio, Gina A. — Director III')
            ->assertJsonCount(2, 'signatories');

        $this->actingAs($chief)->post("/mechanisms/{$mechanism->id}/chief-approve", $this->decision($chief, ['transmittal' => $this->fields()]))->assertRedirect();

        $again = HrMechanism::factory()->for($mechanism->agency)->create(['status' => MechanismStatus::ReviewedByChief]);
        $this->actingAs($chief)->getJson("/mechanisms/{$again->id}/transmittal/compliance")->assertOk()
            ->assertJsonPath('defaults.addressee_name', 'Hon. Michael Dragon T. Javier')
            ->assertJsonPath('defaults.salutation', 'Mayor Javier');
    }

    public function test_the_chief_can_see_and_open_what_was_forwarded_even_for_an_imported_mechanism(): void
    {
        Storage::fake('local');
        $this->imis();
        $mechanism = $this->forReview();
        $mechanism->update(['is_legacy' => true]);
        $mechanism->attachments()->delete();
        foreach (['reviewed' => 'Reviewed MSP.docx', 'checklist' => 'Checklist.pdf'] as $kind => $name) {
            Storage::disk('local')->put("hr-mechanisms/x/{$kind}", 'file contents');
            $mechanism->attachments()->create(['kind' => $kind, 'path' => "hr-mechanisms/x/{$kind}", 'name' => $name]);
        }
        $chief = User::factory()->role(UserRole::PsedChief)->create();

        $documents = collect($this->actingAs($chief)->getJson("/mechanisms/{$mechanism->id}")->assertOk()->json('attachments'))->keyBy('kind');
        $this->assertSame('Reviewed MSP.docx', $documents['reviewed']['name']);
        $this->assertSame("/mechanisms/{$mechanism->id}/attachments/reviewed", $documents['reviewed']['url']);
        $this->assertSame("/mechanisms/{$mechanism->id}/attachments/checklist", $documents['checklist']['url']);
        $this->assertNull($documents['transmittal']['name'], 'not made until the Chief decides');

        $this->actingAs($chief)->get($documents['reviewed']['url'])->assertOk()->assertDownload('Reviewed MSP.docx');

        // The same two documents are listed in the Chief's decision dialogs, wherever they were opened from.
        foreach (['approval', 'compliance'] as $type) {
            $this->actingAs($chief)->getJson("/mechanisms/{$mechanism->id}/transmittal/{$type}")->assertOk()
                ->assertJsonCount(2, 'documents')
                ->assertJsonPath('documents.0.name', 'Reviewed MSP.docx')
                ->assertJsonPath('documents.1.url', "/mechanisms/{$mechanism->id}/attachments/checklist");
        }
    }

    private function letterXml(HrMechanism $mechanism): string
    {
        $file = $mechanism->fresh()->currentAttachments()->firstWhere('kind', 'transmittal');
        $path = tempnam(sys_get_temp_dir(), 'tx');
        file_put_contents($path, Storage::disk('local')->get($file->path));
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        @unlink($path);

        return $xml;
    }

    public function test_paragraphs_rewritten_in_the_editor_replace_the_standard_wording_and_the_rest_stays(): void
    {
        Storage::fake('local');
        Notification::fake();
        $this->imis();
        $mechanism = $this->forReview();
        $chief = User::factory()->role(UserRole::PsedChief)->create();
        $body = [
            // A run list as the editor makes it: plain, bold, italic, bold-italic.
            'p1' => [['This refers to your '], ['2027 Merit Selection Plan', 'b'], [' ('], ['MSP', 'i'], [')', 'bi'], [' and <b>markup</b> & more.']],
            'closing' => [['Respectfully yours,']],
        ];

        $slots = $this->actingAs($chief)->postJson("/mechanisms/{$mechanism->id}/transmittal/approval/view", $this->fields() + ['body' => $body])->assertOk()->json('slots');
        $this->assertSame([['This refers to your '], ['2027 Merit Selection Plan', 'b'], [' ('], ['MSP', 'i'], [')', 'bi'], [' and <b>markup</b> & more.']], $slots['p1']);
        $this->assertSame([['Respectfully yours,']], $slots['closing']);
        $this->assertStringContainsString('is already recommended for approval', $slots['p2'][0][0], 'what was not rewritten is still the standard wording');
        $this->assertSame([['Thank you.']], $slots['thanks']);

        $this->actingAs($chief)->post("/mechanisms/{$mechanism->id}/chief-approve", $this->decision($chief, ['transmittal' => $this->fields() + ['body' => $body]]))->assertRedirect();

        $text = $this->letterText($mechanism);
        $this->assertStringContainsString('This refers to your 2027 Merit Selection Plan (MSP) and <b>markup</b> & more.', $text, 'markup typed in the editor is text, never markup');
        $this->assertStringNotContainsString('which was primarily anchored', $text, 'the standard first paragraph is replaced');
        $this->assertStringContainsString('is already recommended for approval', $text);
        $this->assertStringContainsString('Respectfully yours,', $text);
        $this->assertStringNotContainsString('Very truly yours,', $text);
        $this->assertStringContainsString('Thank you.', $text);

        $xml = $this->letterXml($mechanism);
        $this->assertMatchesRegularExpression('#<w:b/><w:bCs/>(?:(?!</w:r>).)*?<w:t xml:space="preserve">2027 Merit Selection Plan</w:t>#s', $xml, 'bold is kept');
        $this->assertMatchesRegularExpression('#<w:i/><w:iCs/>(?:(?!</w:r>).)*?<w:t xml:space="preserve">MSP</w:t>#s', $xml, 'italic is kept');
        $this->assertStringContainsString('&lt;b&gt;markup&lt;/b&gt; &amp; more.', $xml, 'escaped in the file');
    }

    public function test_the_editor_input_is_checked(): void
    {
        $this->imis();
        $mechanism = $this->forReview();
        $chief = User::factory()->role(UserRole::PsedChief)->create();
        $url = "/mechanisms/{$mechanism->id}/transmittal/approval/view";

        $this->actingAs($chief)->postJson($url, $this->fields() + ['body' => ['date' => [['x']]]])->assertUnprocessable();
        $this->actingAs($chief)->postJson($url, $this->fields() + ['body' => ['p1' => [['x', 'huge']]]])->assertUnprocessable();
        $this->actingAs($chief)->postJson($url, $this->fields() + ['body' => ['p1' => [[str_repeat('x', 3001)]]]])->assertUnprocessable();
        $this->actingAs($chief)->postJson($url, $this->fields() + ['body' => ['p1' => 'plain text']])->assertUnprocessable();

        // A paragraph cleared completely is allowed; it stays as an empty line.
        $this->actingAs($chief)->postJson($url, $this->fields() + ['body' => ['p3' => [['']]]])->assertOk()->assertJsonPath('slots.p3', [['']]);
        $this->actingAs($chief)->postJson($url, $this->fields() + ['body' => ['p3' => []]])->assertOk()->assertJsonPath('slots.p3', [['']]);
    }

    public function test_the_preview_shows_the_letter_as_it_will_be_written_without_making_or_sending_anything(): void
    {
        Storage::fake('local');
        Notification::fake();
        $this->imis();
        $mechanism = $this->forReview();
        $chief = User::factory()->role(UserRole::PsedChief)->create();

        $view = $this->actingAs($chief)->postJson("/mechanisms/{$mechanism->id}/transmittal/approval/view", $this->fields(['signatory_id' => 50]))->assertOk();
        $slots = $view->json('slots');

        $this->assertSame('approval', $view->json('type'));
        $this->assertSame([['9 March 2027']], $slots['date']);
        $this->assertSame([['HON. MICHAEL DRAGON T. JAVIER', 'b']], $slots['name']);
        $this->assertSame(['Municipal Government of Javier', 'Province of Leyte'], $slots['address']);
        $this->assertSame([['Dear '], ['Mayor Javier', 'bi'], [':']], $slots['dear']);
        $this->assertSame([['ATTY. MARILYN E. TALDO', 'b']], $slots['signer']);
        $this->assertSame([['Director IV']], $slots['signer_position']);
        $this->assertStringContainsString('is already recommended for approval', $slots['p2'][0][0]);
        $this->assertSame([['Thank you.']], $slots['thanks']);
        $this->assertSame([['Very truly yours,']], $slots['closing']);

        // The same words end up in the Word file the Chief then generates.
        $this->actingAs($chief)->post("/mechanisms/{$mechanism->id}/chief-approve", $this->decision($chief, ['transmittal' => $this->fields(['signatory_id' => 50])]))->assertRedirect();
        $text = $this->letterText($mechanism);
        foreach (['p1', 'p2', 'p3'] as $paragraph) {
            $this->assertStringContainsString(implode('', array_column($slots[$paragraph], 0)), $text);
        }
    }

    public function test_the_preview_is_checked_and_limited_like_the_letter_itself(): void
    {
        Storage::fake('local');
        Notification::fake();
        $this->imis();
        $mechanism = $this->forReview();
        $chief = User::factory()->role(UserRole::PsedChief)->create();
        $officer = User::factory()->role(UserRole::PsedStaff)->create();

        // Nothing is made or sent by looking.
        $this->actingAs($chief)->postJson("/mechanisms/{$mechanism->id}/transmittal/compliance/view", $this->fields())->assertOk()
            ->assertJsonPath('slots.p1.1.0', '2027 Revised Merit Selection Plan')
            ->assertJsonPath('slots.p1.1.1', 'b');
        $this->assertSame(MechanismStatus::ReviewedByChief, $mechanism->fresh()->status);
        $this->assertNull($mechanism->fresh()->currentAttachments()->firstWhere('kind', 'transmittal'));
        Notification::assertNothingSent();

        $this->actingAs($chief)->postJson("/mechanisms/{$mechanism->id}/transmittal/approval/view", $this->fields(['addressee_name' => '']))->assertUnprocessable()->assertJsonValidationErrors('addressee_name');
        $this->actingAs($chief)->postJson("/mechanisms/{$mechanism->id}/transmittal/approval/view", $this->fields(['signatory_id' => 12]))->assertUnprocessable();
        $this->actingAs($officer)->postJson("/mechanisms/{$mechanism->id}/transmittal/approval/view", $this->fields())->assertForbidden();
    }

    public function test_a_draft_can_be_downloaded_without_sending_anything(): void
    {
        Storage::fake('local');
        Notification::fake();
        $this->imis();
        $mechanism = $this->forReview();

        $response = $this->actingAs(User::factory()->role(UserRole::PsedChief)->create())
            ->post("/mechanisms/{$mechanism->id}/transmittal/approval/preview", $this->fields());

        $response->assertOk()->assertDownload('DRAFT_Transmittal_ForApproval_'.$mechanism->tracking_code.'.docx');
        $this->assertSame(MechanismStatus::ReviewedByChief, $mechanism->fresh()->status);
        $this->assertNull($mechanism->fresh()->currentAttachments()->firstWhere('kind', 'transmittal'));
        Notification::assertNothingSent();
    }

    public function test_only_who_may_take_the_step_may_open_its_letter(): void
    {
        $this->imis();
        $mechanism = $this->forReview();
        $officer = User::factory()->role(UserRole::PsedStaff)->create();

        // Both letters are the PSED Chief's, once the mechanism is with them. The Action Officer, who only forwards, gets neither.
        $mechanism->update(['action_officer_id' => $officer->id]);
        $chief = User::factory()->role(UserRole::PsedChief)->create();
        $this->actingAs($chief)->getJson("/mechanisms/{$mechanism->id}/transmittal/approval")->assertOk();
        $this->actingAs($chief)->getJson("/mechanisms/{$mechanism->id}/transmittal/compliance")->assertOk();
        $this->actingAs($officer)->getJson("/mechanisms/{$mechanism->id}/transmittal/approval")->assertForbidden();
        $this->actingAs($officer)->getJson("/mechanisms/{$mechanism->id}/transmittal/compliance")->assertForbidden();
        $this->actingAs(User::factory()->role(UserRole::Director)->create())->getJson("/mechanisms/{$mechanism->id}/transmittal/compliance")->assertForbidden();
        $this->actingAs($chief)->getJson("/mechanisms/{$mechanism->id}/transmittal/other")->assertNotFound();

        // Before the Chief has it (still being processed), nobody may open a letter.
        $mechanism->update(['status' => MechanismStatus::Processing]);
        $this->actingAs($chief)->getJson("/mechanisms/{$mechanism->id}/transmittal/approval")->assertForbidden();
    }
}
