<?php

namespace App\Mail;

use App\Mail\Concerns\ResolvesRecipients;
use App\Models\SheetSource;
use App\Models\SheetSourceChange;
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
 * screen. The mail deliberately carries no approve link — accepting a batch
 * writes to live locations, so it happens on the review screen behind auth,
 * never from a URL in an inbox.
 */
class SheetChangesAwaitingApproval extends Mailable
{
    use Queueable, ResolvesRecipients, SerializesModels;

    /** How many rows the mail spells out before it stops listing them. */
    public const SAMPLE_SIZE = 12;

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
                'samples' => $this->samples(),
                'sampleOverflow' => max(0, array_sum($this->counts) - self::SAMPLE_SIZE),
                'reviewUrl' => route('sheetSync.index'),
                'sheetUrl' => $this->source->url(),
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

    /**
     * The first handful of rows, so the mail says what changed and not only how
     * much. Anything past that is a job for the review screen.
     *
     * @return list<array{action: string, label: string, detail: string}>
     */
    protected function samples(): array
    {
        return SheetSourceChange::where('sheet_source_id', $this->source->id)
            ->whereIn('action', SheetSourceChange::NEEDS_REVIEW)
            // Creates first: a brand new screening is the thing most worth
            // reading, and an update to a date the thing least worth it.
            ->orderByRaw("CASE action WHEN 'create' THEN 0 WHEN 'update' THEN 1 ELSE 2 END")
            ->orderBy('sheet_row')
            ->limit(self::SAMPLE_SIZE)
            ->get()
            ->map(fn (SheetSourceChange $c) => [
                'action' => match ($c->action) {
                    SheetSourceChange::ACTION_CREATE => 'New',
                    SheetSourceChange::ACTION_UPDATE => 'Changed',
                    default => 'Gone from sheet',
                },
                'label' => $c->label ?: 'Row '.$c->sheet_row,
                'detail' => $this->describe($c),
            ])
            ->all();
    }

    /** Which fields moved, named rather than valued — values belong on screen. */
    protected function describe(SheetSourceChange $change): string
    {
        if ($change->action !== SheetSourceChange::ACTION_UPDATE) {
            return '';
        }

        $fields = array_keys($change->diff ?? []);

        if ($fields === []) {
            return '';
        }

        $fields = array_map(fn ($f) => str_replace('_', ' ', (string) $f), $fields);

        return count($fields) > 4
            ? implode(', ', array_slice($fields, 0, 4)).' and '.(count($fields) - 4).' more'
            : implode(', ', $fields);
    }
}
