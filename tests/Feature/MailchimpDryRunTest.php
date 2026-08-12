<?php

namespace Tests\Feature;

use App\Models\MailchimpImport;
use App\Models\MailchimpImportRow;
use App\Models\User;
use App\Services\MailchimpApi;
use App\Services\MailchimpCredentials;
use App\Services\MailchimpDryRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MailchimpDryRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_it_classifies_every_row_against_the_audience(): void
    {
        $this->fakeAudience([
            ['email_address' => 'already@example.com', 'status' => 'subscribed'],
            ['email_address' => 'invited@example.com', 'status' => 'pending'],
            ['email_address' => 'gone@example.com', 'status' => 'unsubscribed'],
        ], archived: [
            ['email_address' => 'filed@example.com', 'status' => 'subscribed'],
        ]);

        $import = $this->importWithCsv(<<<'CSV'
        Email,First Name
        new@example.com,Ada
        already@example.com,Grace
        invited@example.com,Katherine
        gone@example.com,Joan
        filed@example.com,Radia
        NEW@example.com,Ada Again
        not-an-email,Broken
        ,Nameless
        CSV);

        $counts = $this->dryRun($import);

        $this->assertSame([
            MailchimpImportRow::WILL_SUBSCRIBE => 1,
            MailchimpImportRow::WILL_RESUBSCRIBE => 2,
            MailchimpImportRow::ALREADY_MEMBER => 2,
            MailchimpImportRow::BLOCKED_INVALID => 1,
            MailchimpImportRow::BLOCKED_DUPLICATE => 1,
            MailchimpImportRow::BLOCKED_MISSING => 1,
        ], $counts);

        $this->assertOutcome($import, 1, MailchimpImportRow::WILL_SUBSCRIBE);
        $this->assertOutcome($import, 2, MailchimpImportRow::ALREADY_MEMBER);
        // Invited and waiting to confirm — re-importing would send the opt-in twice.
        $this->assertOutcome($import, 3, MailchimpImportRow::ALREADY_MEMBER);
        $this->assertOutcome($import, 4, MailchimpImportRow::WILL_RESUBSCRIBE);
        // Archived members are absent from the default listing but are not new.
        $this->assertOutcome($import, 5, MailchimpImportRow::WILL_RESUBSCRIBE);
        $this->assertOutcome($import, 7, MailchimpImportRow::BLOCKED_INVALID);
        $this->assertOutcome($import, 8, MailchimpImportRow::BLOCKED_MISSING);
    }

    public function test_duplicates_are_matched_regardless_of_case_and_point_at_the_first_row(): void
    {
        $this->fakeAudience([]);

        $import = $this->importWithCsv(<<<'CSV'
        Email,First Name
        maria@example.com,Maria
        MARIA@Example.com,Maria Again
        CSV);

        $this->dryRun($import);

        $row = $import->rows()->where('row_number', 2)->firstOrFail();

        $this->assertSame(MailchimpImportRow::BLOCKED_DUPLICATE, $row->outcome);
        $this->assertSame('Same address as row 1.', $row->detail);
        // The first occurrence is the one that gets imported.
        $this->assertOutcome($import, 1, MailchimpImportRow::WILL_SUBSCRIBE);
    }

    public function test_a_small_file_asks_about_its_own_addresses_rather_than_reading_the_audience(): void
    {
        $this->fakeAudience([
            ['email_address' => 'already@example.com', 'status' => 'subscribed'],
        ]);

        $import = $this->importWithCsv("Email\nalready@example.com\nnew@example.com");

        $this->dryRun($import);

        // Two lookups, no paging: a forty-row file must not drag a whole audience
        // across the wire, which is what made this slow enough to be killed by the
        // queue's per-job timeout.
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/members/'.md5('already@example.com')));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'count=1000'));

        $this->assertOutcome($import, 1, MailchimpImportRow::ALREADY_MEMBER);
        $this->assertOutcome($import, 2, MailchimpImportRow::WILL_SUBSCRIBE);
    }

    public function test_a_large_file_reads_the_audience_instead(): void
    {
        $this->fakeAudience([
            ['email_address' => 'contact400@example.com', 'status' => 'unsubscribed'],
        ]);

        $rows = collect(range(1, 600))->map(fn ($n) => "contact{$n}@example.com")->implode("\n");
        $import = $this->importWithCsv("Email\n{$rows}");

        $this->dryRun($import);

        // Past the threshold the per-address route would be 600 requests; paging is
        // two (the listing plus the archived pass).
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'count=1000'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/members/'.md5('contact1@example.com')));

        $this->assertOutcome($import, 400, MailchimpImportRow::WILL_RESUBSCRIBE);
        $this->assertOutcome($import, 1, MailchimpImportRow::WILL_SUBSCRIBE);
    }

    public function test_a_second_dry_run_replaces_the_first_classification(): void
    {
        $this->fakeAudience([]);

        $import = $this->importWithCsv(<<<'CSV'
        Email
        one@example.com
        two@example.com
        CSV);

        $this->dryRun($import);
        $this->dryRun($import);

        $this->assertSame(2, $import->rows()->count());
    }

    public function test_the_preview_endpoint_reports_the_summary(): void
    {
        $this->fakeAudience([
            ['email_address' => 'already@example.com', 'status' => 'subscribed'],
        ]);

        $import = $this->importWithCsv(<<<'CSV'
        Email
        new@example.com
        already@example.com
        CSV);

        $this->dryRun($import);
        $import->update(['status' => MailchimpImport::STATUS_DRY_RUN_COMPLETE]);

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)
            ->getJson(route('mailchimpImport.preview', ['import' => $import->id]));

        $response->assertOk()
            ->assertJsonPath('ready', true)
            ->assertJsonPath('actionable', 1)
            ->assertJsonPath('counts.'.MailchimpImportRow::WILL_SUBSCRIBE, 1)
            ->assertJsonPath('counts.'.MailchimpImportRow::ALREADY_MEMBER, 1);

        $filtered = $this->actingAs($admin)->getJson(route('mailchimpImport.preview', [
            'import' => $import->id,
            'outcome' => MailchimpImportRow::ALREADY_MEMBER,
        ]));

        $filtered->assertOk()
            ->assertJsonCount(1, 'rows')
            ->assertJsonPath('rows.0.email', 'already@example.com');
    }

    public function test_a_double_optin_audience_says_invited_rather_than_subscribed(): void
    {
        $this->fakeAudience([]);

        $import = $this->importWithCsv("Email\nnew@example.com");
        $import->update(['double_optin' => true, 'status' => MailchimpImport::STATUS_DRY_RUN_COMPLETE]);

        $this->dryRun($import);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->getJson(route('mailchimpImport.preview', ['import' => $import->id]))
            ->assertOk()
            ->assertJsonPath('labels.'.MailchimpImportRow::WILL_SUBSCRIBE, 'Will be invited');
    }

    /**
     * @return array<string, int>
     */
    protected function dryRun(MailchimpImport $import): array
    {
        return app(MailchimpDryRun::class)->run(
            $import,
            MailchimpApi::for(MailchimpCredentials::fromApiKey('anz', 'key-us1', 'us1')),
            Storage::disk('local')->path($import->stored_path),
        );
    }

    /**
     * Serves both routes the dry run can take: per-address lookups for a small
     * file, and the paged listing for a large one. Which one a test exercises
     * depends on how many rows it has, so the fake answers either.
     *
     * @param  array<int, array<string, string>>  $members
     * @param  array<int, array<string, string>>  $archived
     */
    protected function fakeAudience(array $members, array $archived = []): void
    {
        $byHash = [];

        foreach ($members as $member) {
            $byHash[md5(strtolower($member['email_address']))] = $member['status'];
        }
        foreach ($archived as $member) {
            $byHash[md5(strtolower($member['email_address']))] = 'archived';
        }

        Http::fake(function (Request $request) use ($members, $archived, $byHash) {
            // GET /lists/{id}/members/{md5} — one address.
            if (preg_match('#/members/([a-f0-9]{32})#', $request->url(), $matches)) {
                return isset($byHash[$matches[1]])
                    ? Http::response(['status' => $byHash[$matches[1]]])
                    : Http::response(['title' => 'Resource Not Found'], 404);
            }

            // GET /lists/{id}/members — the paged listing, archived asked for by name.
            $page = str_contains($request->url(), 'status=archived') ? $archived : $members;

            return Http::response(['members' => $page, 'total_items' => count($page)]);
        });
    }

    protected function importWithCsv(string $csv): MailchimpImport
    {
        Storage::disk('local')->put('mailchimp-imports/test.csv', $csv);

        return MailchimpImport::create([
            'account' => 'anz',
            'filename' => 'contacts.csv',
            'stored_path' => 'mailchimp-imports/test.csv',
            'original_row_count' => substr_count(trim($csv), "\n"),
            'audience_id' => 'aud123',
            'audience_name' => 'Great Walks',
            'field_map' => ['Email' => 'EMAIL', 'First Name' => 'FNAME'],
            'double_optin' => false,
            'status' => MailchimpImport::STATUS_PENDING,
        ]);
    }

    protected function assertOutcome(MailchimpImport $import, int $rowNumber, string $expected): void
    {
        $row = $import->rows()->where('row_number', $rowNumber)->first();

        $this->assertNotNull($row, "Row {$rowNumber} was not classified.");
        $this->assertSame($expected, $row->outcome, "Row {$rowNumber} was classified as {$row->outcome}.");
    }
}
