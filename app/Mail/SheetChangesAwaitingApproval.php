<?php

namespace App\Mail;

use App\Mail\Concerns\ResolvesRecipients;
use App\Models\SheetSource;
use App\Support\EmailDetails;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells the owner that a master sheet tab is holding work nobody has accepted.
 *
 * Sent from the sync job, not from a request: the batch that matters is the one
 * that appears at 3am from the scheduled run, when nobody is looking at the
 * screen. Every waiting row is listed with the values it would write, so the
 * mail can be read on its own. It deliberately carries no approve link —
 * accepting a batch writes to live locations, so it never happens from a URL in
 * an inbox.
 */
class SheetChangesAwaitingApproval extends Mailable
{
    use Queueable, ResolvesRecipients, SerializesModels;

    public function __construct(
        public SheetSource $source,
        public array $counts,
    ) {}

    /**
     * The mailboxes to alert, in order of preference.
     *
     * Falls back to the master sheet owner because that is the only account the
     * review screen lets in — an alert to anyone else is a dead end.
     *
     * @return list<string>
     */
    public static function recipients(): array
    {
        return self::firstConfigured([
            'mail.approval_recipients',
            'services.google.sheets.owner_email',
        ]);
    }

    /**
     * The film this tab's screenings belong to, for the subject line.
     *
     * A tab is named for a region ("VIC/TAS"), which says nothing about which
     * tour moved — and several tours run at once. The film is what makes the
     * subject readable in a full inbox.
     */
    public function film(): ?string
    {
        return $this->source->event?->film?->name;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '%s: %s — %s on %s',
                config('mail.brand'),
                $this->film() ?? $this->source->event?->event_name ?? 'Master sheet',
                $this->headline(),
                $this->source->tab_name
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.sheet-changes-awaiting-approval',
            with: [
                'headline' => $this->headline(),
                'event' => $this->source->event?->event_name,
                'film' => $this->film(),
                'rows' => EmailDetails::sheetChanges($this->source->id),
            ],
        );
    }

    /** "2 new locations and 1 change", phrased for a subject line. */
    public function headline(): string
    {
        $parts = [];

        if ($n = $this->counts['creates'] ?? 0) {
            $parts[] = $n.' new location'.($n === 1 ? '' : 's');
        }

        if ($n = $this->counts['updates'] ?? 0) {
            $parts[] = $n.' change'.($n === 1 ? '' : 's');
        }

        if ($n = $this->counts['missing'] ?? 0) {
            $parts[] = $n.' removed from the sheet';
        }

        if ($parts === []) {
            return 'changes to review';
        }

        $last = array_pop($parts);

        return $parts === [] ? $last : implode(', ', $parts).' and '.$last;
    }
}
