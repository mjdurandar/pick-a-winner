<?php

namespace App\Mail;

use App\Mail\Concerns\ResolvesRecipients;
use App\Models\FilmSiteCheck;
use App\Models\FilmSiteCheckRun;
use App\Services\FilmSiteComparisonService;
use App\Support\EmailDetails;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells us a film's website and the Win App have stopped agreeing.
 *
 * Sent per check, and only for differences this run had not seen before — the
 * comparison already works out which are new, so the daily re-run of the same
 * twenty-five known warnings sends nothing.
 *
 * The mail lists every standing difference in full — both sides of each one —
 * so it can be acted on without opening the app. The ones new this run are
 * tagged, which is what triggered the send.
 */
class FilmSiteDifferencesFound extends Mailable
{
    use Queueable, ResolvesRecipients, SerializesModels;

    /**
     * @param  bool  $everything  List every standing difference, not only the new
     *                            ones — for a send someone asked for, where "what
     *                            is wrong right now" is the question.
     */
    public function __construct(
        public FilmSiteCheck $check,
        public FilmSiteCheckRun $run,
        public bool $everything = false,
    ) {}

    /** @return list<string> */
    public static function recipients(): array
    {
        return self::firstConfigured([
            'mail.site_check_recipients',
            'mail.approval_recipients',
            'services.google.sheets.owner_email',
        ]);
    }

    /** @return list<string> */
    public static function ccRecipients(): array
    {
        return self::ccFrom('mail.site_check_cc');
    }

    /**
     * The film whose site this is. Several tours run at once and the site's
     * domain is not always obviously one of them, so the film leads the subject.
     */
    public function film(): ?string
    {
        return $this->check->event?->film?->name;
    }

    public function envelope(): Envelope
    {
        $n = $this->newCount();

        return new Envelope(
            subject: sprintf(
                '%s: %s — %d %sdifference%s between the site and the app',
                config('mail.brand'),
                $this->film() ?? $this->check->event?->event_name ?? 'Film site',
                $n,
                $this->everything ? '' : 'new ',
                $n === 1 ? '' : 's'
            ),
            cc: self::ccRecipients(),
        );
    }

    public function content(): Content
    {
        $items = EmailDetails::siteItems($this->run->items ?? []);

        return new Content(
            markdown: 'emails.film-site-differences',
            with: [
                'everything' => $this->everything,
                'film' => $this->film(),
                'event' => $this->check->event?->event_name,
                'site' => preg_replace('#^https?://(www\.)?#', '', rtrim($this->check->site_url, '/')),
                'label' => $this->check->label(),
                'problems' => $items['problems'],
                'notices' => $items['notices'],
                'newCount' => self::actionable($this->run)->count(),
                'errors' => (int) $this->run->errors,
                'warnings' => (int) $this->run->warnings,
                'matched' => (int) $this->run->matched,
                'resolved' => (int) $this->run->resolved_issues,
            ],
        );
    }

    /**
     * The differences worth waking someone for: new this run, and not a notice.
     *
     * Notices are history — a screening that has happened, whose date the site
     * has stopped publishing. They turn up as "new" every time a show ages past
     * its date, so alerting on them would mail all season about nothing anyone
     * can act on. They stay on the dashboard and in Monday's digest.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public static function actionable(FilmSiteCheckRun $run, bool $everything = false): \Illuminate\Support\Collection
    {
        return collect($run->items ?? [])
            ->when(! $everything, fn ($items) => $items->where('new', true))
            ->whereIn('severity', [
                FilmSiteComparisonService::SEVERITY_ERROR,
                FilmSiteComparisonService::SEVERITY_WARNING,
            ])
            // A date mismatch is a wrong answer given to ticket buyers; a missing
            // listing is a gap. Errors first.
            ->sortBy(fn ($i) => ($i['severity'] ?? '') === FilmSiteComparisonService::SEVERITY_ERROR ? 0 : 1)
            ->values();
    }

    /** How many of those there are, for the subject line and the trigger. */
    public function newCount(): int
    {
        return self::actionable($this->run, $this->everything)->count();
    }
}
