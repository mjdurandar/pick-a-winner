<?php

namespace Tests\Feature;

use App\Models\Events;
use App\Models\Films;
use App\Models\NewsletterResubscribeAttempt;
use App\Models\User;
use App\Services\MailchimpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NewsletterResubscribeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.mailchimp.anz.key', 'key-us13');
        Config::set('services.mailchimp.anz.server', 'us13');
    }

    /**
     * The message is verbatim from a real Mailchimp 400. The old check looked for
     * "Compliance State" with capitals, never matched, and so reported every
     * refused contact to the sign-up form as successfully resubscribed.
     */
    #[DataProvider('complianceBodies')]
    public function test_a_compliance_refusal_is_detected_whatever_the_casing(array $body): void
    {
        Http::fake(['*/lists/*/members/*' => Http::response($body, 400)]);

        $result = (new MailchimpService('anz'))->resubscribe('list1', 'gone@example.com');

        $this->assertIsArray($result);
        $this->assertSame('compliance_skipped', $result['status']);
    }

    public static function complianceBodies(): array
    {
        return [
            'lowercase detail, as Mailchimp actually sends it' => [[
                'title' => 'Forgotten Email Not Subscribed',
                'detail' => 'gone@example.com is in a compliance state due to unsubscribe, bounce, or compliance review and cannot be subscribed.',
            ]],
            'capitalised detail' => [[
                'detail' => 'gone@example.com is in a Compliance State.',
            ]],
            'title only' => [[
                'title' => 'Forgotten Email Not Subscribed',
            ]],
        ];
    }

    public function test_a_compliance_refusal_carries_mailchimps_own_reason(): void
    {
        $detail = 'gone@example.com is in a compliance state due to unsubscribe, bounce, or compliance review and cannot be subscribed.';

        Http::fake(['*/lists/*/members/*' => Http::response([
            'title' => 'Forgotten Email Not Subscribed',
            'detail' => $detail,
        ], 400)]);

        $result = (new MailchimpService('anz'))->resubscribe('list1', 'gone@example.com');

        $this->assertSame($detail, $result['detail']);
    }

    public function test_an_unrelated_400_still_throws(): void
    {
        Http::fake(['*/lists/*/members/*' => Http::response([
            'title' => 'Invalid Resource',
            'detail' => 'Your merge fields were invalid.',
        ], 400)]);

        $this->expectException(\Exception::class);

        (new MailchimpService('anz'))->resubscribe('list1', 'someone@example.com');
    }

    public function test_the_report_covers_every_outcome_and_names_the_film(): void
    {
        $film = Films::create(['name' => 'Great Walks']);
        $event = $this->eventFor($film);

        NewsletterResubscribeAttempt::create([
            'event_id' => $event->id,
            'email' => 'back@example.com',
            'outcome' => NewsletterResubscribeAttempt::RESUBSCRIBED,
            'list_name' => 'Adventure Entertainment Newsletter ANZ',
        ]);
        NewsletterResubscribeAttempt::create([
            'event_id' => $event->id,
            'email' => 'gone@example.com',
            'outcome' => NewsletterResubscribeAttempt::BLOCKED_COMPLIANCE,
            'detail' => 'gone@example.com is in a compliance state due to unsubscribe, bounce, or compliance review.',
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $all = $this->actingAs($admin)->get(route('newsletterResubscribes.download'));
        $all->assertOk();

        $csv = $all->streamedContent();

        $this->assertStringContainsString('Film,Event,Location,Email,Outcome,Reason', $csv);
        $this->assertStringContainsString('Great Walks', $csv);
        $this->assertStringContainsString('back@example.com', $csv);
        $this->assertStringContainsString('gone@example.com', $csv);
        $this->assertStringContainsString('compliance state', $csv);

        // The blocked-only report is the "who could we not bring back" list.
        $blocked = $this->actingAs($admin)
            ->get(route('newsletterResubscribes.download', ['report' => 'blocked']))
            ->streamedContent();

        $this->assertStringContainsString('gone@example.com', $blocked);
        $this->assertStringNotContainsString('back@example.com', $blocked);
    }

    public function test_the_report_can_be_narrowed_to_one_film(): void
    {
        $wanted = Films::create(['name' => 'Great Walks']);
        $other = Films::create(['name' => 'Ocean Film Tour']);

        foreach ([[$wanted, 'walks@example.com'], [$other, 'ocean@example.com']] as [$film, $email]) {
            $event = $this->eventFor($film);

            NewsletterResubscribeAttempt::create([
                'event_id' => $event->id,
                'email' => $email,
                'outcome' => NewsletterResubscribeAttempt::RESUBSCRIBED,
            ]);
        }

        $csv = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('newsletterResubscribes.download', ['film_id' => $wanted->id]))
            ->streamedContent();

        $this->assertStringContainsString('walks@example.com', $csv);
        $this->assertStringNotContainsString('ocean@example.com', $csv);
    }

    public function test_a_host_cannot_download_the_report(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'host']))
            ->get(route('newsletterResubscribes.download'))
            ->assertForbidden();
    }

    /**
     * The events table predates this feature and has several non-null columns that
     * matter to nothing here; they are filled so the report has a film to name.
     */
    protected function eventFor(Films $film): Events
    {
        return Events::create([
            'film_id' => $film->id,
            'event_name' => $film->name.' 2026',
            'event_uuid' => (string) Str::uuid(),
            'event_description' => 'Test event',
            'event_date' => '2026-03-01',
            'event_banner' => 'banner.jpg',
            'event_coordinator' => 'Coordinator',
            'event_coordinator_email' => 'coordinator@example.com',
            'event_country' => 'Australia',
        ]);
    }
}
