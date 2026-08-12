<?php

namespace Tests\Feature;

use App\Exceptions\MailchimpApiException;
use App\Jobs\RunMailchimpImportJob;
use App\Models\MailchimpImport;
use App\Models\MailchimpImportRow;
use App\Models\User;
use App\Services\MailchimpApi;
use App\Services\MailchimpCredentials;
use App\Services\MailchimpImportRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MailchimpImportRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_it_batches_new_contacts_and_records_each_outcome(): void
    {
        Http::fake([
            '*/lists/aud123' => Http::response([
                'new_members' => [['email_address' => 'ada@example.com']],
                'errors' => [[
                    'email_address' => 'bad@example.com',
                    'error' => 'Please provide a valid email address.',
                    'error_code' => 'ERROR_CONTACT_EXISTS',
                ]],
            ]),
        ]);

        $import = $this->importReadyToRun("Email,First Name\nada@example.com,Ada\nbad@example.com,Bad", [
            1 => MailchimpImportRow::WILL_SUBSCRIBE,
            2 => MailchimpImportRow::WILL_SUBSCRIBE,
        ]);

        $this->runImport($import);

        $this->assertOutcome($import, 1, MailchimpImportRow::SUBSCRIBED);
        $this->assertOutcome($import, 2, MailchimpImportRow::FAILED);

        $this->assertSame(1, $import->refresh()->subscribed_count);
        $this->assertSame(1, $import->failed_count);
    }

    public function test_the_batch_carries_merge_fields_the_tag_and_the_member_status(): void
    {
        Http::fake([
            '*/lists/aud123' => Http::response([
                'new_members' => [['email_address' => 'ada@example.com']],
                'errors' => [],
            ]),
        ]);

        $import = $this->importReadyToRun("Email,First Name\nada@example.com,Ada", [
            1 => MailchimpImportRow::WILL_SUBSCRIBE,
        ]);
        $import->update(['tag' => 'CSV Import Jan']);

        $this->runImport($import);

        Http::assertSent(function (Request $request) {
            $member = $request->data()['members'][0];

            return $request->method() === 'POST'
                && $request->data()['update_existing'] === false
                && $member['email_address'] === 'ada@example.com'
                && $member['status'] === 'subscribed'
                && $member['merge_fields'] === ['FNAME' => 'Ada']
                && $member['tags'] === ['CSV Import Jan'];
        });
    }

    public function test_a_double_optin_audience_sends_pending_rather_than_subscribed(): void
    {
        Http::fake([
            '*/lists/aud123' => Http::response(['new_members' => [], 'errors' => []]),
        ]);

        $import = $this->importReadyToRun("Email\nada@example.com", [
            1 => MailchimpImportRow::WILL_SUBSCRIBE,
        ]);
        $import->update(['double_optin' => true]);

        $this->runImport($import);

        Http::assertSent(fn (Request $request) => $request->data()['members'][0]['status'] === 'pending');
    }

    public function test_resubscribes_are_patched_individually_and_tagged(): void
    {
        Http::fake([
            '*/lists/aud123/members/*/tags' => Http::response([], 204),
            '*/lists/aud123/members/*' => Http::response(['status' => 'subscribed']),
        ]);

        $import = $this->importReadyToRun("Email\ngone@example.com", [
            1 => MailchimpImportRow::WILL_RESUBSCRIBE,
        ]);
        $import->update(['tag' => 'Recovered']);

        $this->runImport($import);

        $this->assertOutcome($import, 1, MailchimpImportRow::RESUBSCRIBED);
        $this->assertSame(1, $import->refresh()->resubscribed_count);

        $hash = md5('gone@example.com');

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), "/members/{$hash}")
            && $request->data() === ['status' => 'subscribed']);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), "/members/{$hash}/tags"));
    }

    /**
     * The wording is taken verbatim from a real Mailchimp 400 — lowercase
     * "compliance state". An earlier version of this test invented the message with
     * different casing, the match was case-sensitive, and the refusal escaped as a
     * fatal error that ended the whole run.
     */
    #[DataProvider('complianceRefusals')]
    public function test_a_compliance_refusal_is_recorded_rather_than_thrown(array $body): void
    {
        Http::fake([
            '*/lists/aud123/members/*' => Http::response($body, 400),
        ]);

        $import = $this->importReadyToRun("Email\ngone@example.com", [
            1 => MailchimpImportRow::WILL_RESUBSCRIBE,
        ]);

        $this->runImport($import);

        $row = $import->rows()->where('row_number', 1)->firstOrFail();

        $this->assertSame(MailchimpImportRow::BLOCKED_UNSUBSCRIBED, $row->outcome);
        $this->assertSame(400, $row->mailchimp_status_code);
        $this->assertSame(0, $import->refresh()->resubscribed_count);

        // The report answers "why" with Mailchimp's words, not ours.
        $this->assertNotEmpty($row->detail);
        $this->assertSame($body['detail'] ?? $body['title'], $row->detail);
    }

    public function test_the_report_downloads_every_row_with_its_reason(): void
    {
        Http::fake([
            '*/lists/aud123/members/*' => Http::response([
                'detail' => 'gone@example.com is in a compliance state due to unsubscribe, bounce, or compliance review and cannot be subscribed.',
            ], 400),
        ]);

        $import = $this->importReadyToRun("Email\ngone@example.com\nalready@example.com", [
            1 => MailchimpImportRow::WILL_RESUBSCRIBE,
            2 => MailchimpImportRow::ALREADY_MEMBER,
        ]);

        $this->runImport($import);

        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('mailchimpImport.download', ['import' => $import->id]));

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Row,Email,Outcome,Reason', $csv);
        $this->assertStringContainsString('gone@example.com', $csv);
        $this->assertStringContainsString('compliance state', $csv);
        $this->assertStringContainsString('Already a member', $csv);
    }

    public static function complianceRefusals(): array
    {
        return [
            'lowercase detail, as Mailchimp actually sends it' => [[
                'title' => 'Forgotten Email Not Subscribed',
                'detail' => 'gone@example.com is in a compliance state due to unsubscribe, bounce, or compliance review and cannot be subscribed.',
            ]],
            'title only' => [[
                'title' => 'Forgotten Email Not Subscribed',
            ]],
            'capitalised detail' => [[
                'detail' => 'gone@example.com is in a Compliance State.',
            ]],
        ];
    }

    public function test_an_ordinary_400_still_stops_the_run(): void
    {
        Http::fake([
            '*/lists/aud123/members/*' => Http::response([
                'title' => 'Invalid Resource',
                'detail' => 'Your merge fields were invalid.',
            ], 400),
        ]);

        $import = $this->importReadyToRun("Email\ngone@example.com", [
            1 => MailchimpImportRow::WILL_RESUBSCRIBE,
        ]);

        $this->expectException(MailchimpApiException::class);

        $this->runImport($import);
    }

    public function test_a_row_mailchimp_already_holds_is_not_reported_as_a_failure(): void
    {
        // What a replayed batch looks like after a worker died before recording the
        // first attempt's response.
        Http::fake([
            '*/lists/aud123' => Http::response([
                'new_members' => [],
                'errors' => [[
                    'email_address' => 'ada@example.com',
                    'error' => 'ada@example.com is already a list member.',
                    'error_code' => 'ERROR_CONTACT_EXISTS',
                ]],
            ]),
        ]);

        $import = $this->importReadyToRun("Email\nada@example.com", [
            1 => MailchimpImportRow::WILL_SUBSCRIBE,
        ]);

        $this->runImport($import);

        $this->assertOutcome($import, 1, MailchimpImportRow::ALREADY_MEMBER);
        $this->assertSame(0, $import->refresh()->failed_count);
    }

    public function test_a_second_run_does_not_resend_rows_that_are_already_done(): void
    {
        Http::fake([
            '*/lists/aud123' => Http::response([
                'new_members' => [['email_address' => 'ada@example.com']],
                'errors' => [],
            ]),
        ]);

        $import = $this->importReadyToRun("Email\nada@example.com", [
            1 => MailchimpImportRow::WILL_SUBSCRIBE,
        ]);

        $this->runImport($import);
        $this->runImport($import);

        // The row stopped being actionable after the first run, so the retry had
        // nothing to send and never called Mailchimp again.
        Http::assertSentCount(1);
        $this->assertSame(1, $import->refresh()->subscribed_count);
    }

    public function test_rows_that_were_never_actionable_are_left_alone(): void
    {
        Http::fake();

        $import = $this->importReadyToRun("Email\nalready@example.com", [
            1 => MailchimpImportRow::ALREADY_MEMBER,
        ]);

        $this->runImport($import);

        Http::assertNothingSent();
        $this->assertOutcome($import, 1, MailchimpImportRow::ALREADY_MEMBER);
    }

    public function test_an_import_cannot_be_sent_without_a_preview(): void
    {
        Queue::fake();

        $import = $this->importReadyToRun("Email\nada@example.com", [
            1 => MailchimpImportRow::WILL_SUBSCRIBE,
        ]);
        $import->update(['status' => MailchimpImport::STATUS_PENDING]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('mailchimpImport.run', ['import' => $import->id]))
            ->assertStatus(422);

        Queue::assertNothingPushed();
        $this->assertSame(MailchimpImport::STATUS_PENDING, $import->refresh()->status);
    }

    public function test_sending_claims_the_import_before_the_job_is_queued(): void
    {
        Queue::fake();

        $import = $this->importReadyToRun("Email\nada@example.com", [
            1 => MailchimpImportRow::WILL_SUBSCRIBE,
        ]);
        $import->update([
            'status' => MailchimpImport::STATUS_DRY_RUN_COMPLETE,
            'consent_confirmed_by_user_id' => User::factory()->create(['role' => 'admin'])->id,
            'consent_confirmed_at' => now(),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->postJson(route('mailchimpImport.run', ['import' => $import->id]))
            ->assertOk()
            ->assertJsonPath('running', true);

        Queue::assertPushed(RunMailchimpImportJob::class, 1);
        $this->assertSame(MailchimpImport::STATUS_RUNNING, $import->refresh()->status);

        // A second click must not queue a second send.
        $this->actingAs($admin)
            ->postJson(route('mailchimpImport.run', ['import' => $import->id]))
            ->assertOk();

        Queue::assertPushed(RunMailchimpImportJob::class, 1);
    }

    public function test_a_failed_run_can_be_resumed(): void
    {
        Queue::fake();

        $import = $this->importReadyToRun("Email\nada@example.com", [
            1 => MailchimpImportRow::WILL_SUBSCRIBE,
        ]);
        $import->update([
            'status' => MailchimpImport::STATUS_FAILED,
            'failure_reason' => 'The worker died halfway through.',
            'completed_at' => now(),
            'consent_confirmed_by_user_id' => User::factory()->create(['role' => 'admin'])->id,
            'consent_confirmed_at' => now(),
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('mailchimpImport.run', ['import' => $import->id]))
            ->assertOk()
            ->assertJsonPath('running', true);

        Queue::assertPushed(RunMailchimpImportJob::class, 1);

        $import->refresh();

        $this->assertSame(MailchimpImport::STATUS_RUNNING, $import->status);
        $this->assertNull($import->failure_reason);
        $this->assertNull($import->completed_at);
    }

    protected function runImport(MailchimpImport $import): void
    {
        app(MailchimpImportRunner::class)->run(
            $import,
            MailchimpApi::for(MailchimpCredentials::fromApiKey('anz', 'key-us1', 'us1')),
            Storage::disk('local')->path($import->stored_path),
        );
    }

    /**
     * @param  array<int, string>  $outcomes  row number => dry-run outcome
     */
    protected function importReadyToRun(string $csv, array $outcomes): MailchimpImport
    {
        Storage::disk('local')->put('mailchimp-imports/run.csv', $csv);

        $import = MailchimpImport::create([
            'account' => 'anz',
            'filename' => 'contacts.csv',
            'stored_path' => 'mailchimp-imports/run.csv',
            'original_row_count' => count($outcomes),
            'audience_id' => 'aud123',
            'audience_name' => 'Great Walks',
            'field_map' => ['Email' => 'EMAIL', 'First Name' => 'FNAME'],
            'double_optin' => false,
            'status' => MailchimpImport::STATUS_RUNNING,
        ]);

        foreach ($outcomes as $rowNumber => $outcome) {
            $import->rows()->create([
                'row_number' => $rowNumber,
                'email' => trim(explode(',', explode("\n", $csv)[$rowNumber])[0]),
                'outcome' => $outcome,
            ]);
        }

        return $import;
    }

    protected function assertOutcome(MailchimpImport $import, int $rowNumber, string $expected): void
    {
        $row = $import->rows()->where('row_number', $rowNumber)->first();

        $this->assertNotNull($row, "Row {$rowNumber} is missing.");
        $this->assertSame($expected, $row->outcome, "Row {$rowNumber} ended as {$row->outcome}.");
    }
}
