<?php

namespace App\Mail;

use App\Mail\Concerns\ResolvesRecipients;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The Monday digest: last week's screenings and what they collected, plus
 * anything the master sheet sync and the film site checks are still waiting on.
 *
 * Carries no approve or fix links. Both syncs are read-only and both sets of
 * decisions write to live locations, so they happen on their own screens behind
 * auth — the mail's job is to make sure nobody has to remember to look.
 */
class WeeklyDigest extends Mailable
{
    use Queueable, ResolvesRecipients, SerializesModels;

    public function __construct(
        public array $digest,
    ) {}

    /**
     * Who gets the digest, in order of preference.
     *
     * Falls back to the approval recipients, and then to the master sheet owner:
     * unlike the screening figures, half this mail is work only they can action,
     * so an empty config should still reach someone who can do something.
     *
     * @return list<string>
     */
    public static function recipients(): array
    {
        return self::firstConfigured([
            'mail.weekly_digest_recipients',
            'mail.approval_recipients',
            'services.google.sheets.owner_email',
        ]);
    }

    /** @return list<string> */
    public static function ccRecipients(): array
    {
        return self::ccFrom('mail.weekly_digest_cc');
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '%s: week of %s — %s',
                config('mail.brand'),
                $this->window('start')->format('j M'),
                $this->headline()
            ),
            cc: self::ccRecipients(),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.weekly-digest',
            with: [
                'd' => $this->digest,
                'start' => $this->window('start'),
                'end' => $this->window('end'),
                'headline' => $this->headline(),
                'attention' => $this->attention(),
                'sheetUrl' => route('sheetSync.index'),
                'siteUrl' => route('filmSiteChecks.index'),
                'reportUrl' => route('weekly-report'),
            ],
        );
    }

    /** "18 screenings, 402 sign-ups", or what is wrong if anything is. */
    public function headline(): string
    {
        $t = $this->digest['totals'];

        $parts = [];
        $parts[] = $t['screenings'].' screening'.($t['screenings'] === 1 ? '' : 's');
        $parts[] = number_format($t['signups']).' sign-up'.($t['signups'] === 1 ? '' : 's');

        if ($this->needsAttention()) {
            $parts[] = 'sync needs a look';
        }

        return implode(', ', $parts);
    }

    /** Whether either sync is holding something a person has to decide. */
    public function needsAttention(): bool
    {
        return $this->sheetNeedsAttention() || $this->siteNeedsAttention();
    }

    /** Parked sheet rows nobody has accepted. */
    public function sheetNeedsAttention(): bool
    {
        return array_sum($this->digest['sheetTotals']) > 0;
    }

    /** A film site disagreeing with the app, or a check that has stopped running. */
    public function siteNeedsAttention(): bool
    {
        $site = $this->digest['siteTotals'];

        return $site['errors'] > 0 || $site['warnings'] > 0 || $site['stale'] > 0;
    }

    /**
     * The line under the summary, naming whichever sync actually wants someone.
     *
     * "Both syncs have something waiting" over a section reading "Nothing parked"
     * is worse than no line at all — it teaches people the mail is not accurate.
     */
    public function attention(): string
    {
        return match (true) {
            $this->sheetNeedsAttention() && $this->siteNeedsAttention() => '**Both syncs have something waiting** — the sections below say what.',
            $this->sheetNeedsAttention() => '**The master sheet sync has changes waiting** for approval. The website sync is clean.',
            $this->siteNeedsAttention() => '**The website sync has differences** to look at. The master sheet is up to date.',
            default => 'Both syncs are clean. Nothing needs you.',
        };
    }

    protected function window(string $edge): CarbonImmutable
    {
        return $this->digest['window'][$edge];
    }
}
