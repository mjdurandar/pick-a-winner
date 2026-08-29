<?php

namespace App\Console\Commands;

use App\Jobs\SyncSheetSourceJob;
use App\Models\SheetSource;
use Illuminate\Console\Command;

class SyncMasterSheets extends Command
{
    protected $signature = 'sheets:sync
                            {--source= : Sync one source by id instead of every enabled one}
                            {--queue : Dispatch to the queue instead of running inline}
                            {--status : Show what each source last did and exit}';

    protected $description = 'Pull the configured master schedule tabs into locations (read-only against Google)';

    public function handle(): int
    {
        if ($this->option('status')) {
            return $this->showStatus();
        }

        $sources = SheetSource::query()
            ->when($this->option('source'), fn ($q, $id) => $q->whereKey($id))
            ->when(! $this->option('source'), fn ($q) => $q->enabled())
            ->with('event')
            ->get();

        if ($sources->isEmpty()) {
            $this->warn('No sources to sync. Add one on the master sheet screen first.');

            return self::SUCCESS;
        }

        foreach ($sources as $source) {
            // Inline by default: someone typing this wants to watch it happen and
            // see the failure, not go looking for it in the queue's log.
            $this->option('queue')
                ? SyncSheetSourceJob::dispatch($source->id)
                : SyncSheetSourceJob::dispatchSync($source->id);

            $this->line("  {$source->tab_name}".($this->option('queue') ? ' queued' : ''));
        }

        if ($this->option('queue')) {
            return self::SUCCESS;
        }

        $this->newLine();

        return $this->showStatus();
    }

    protected function showStatus(): int
    {
        $sources = SheetSource::with('event')->orderBy('id')->get();

        if ($sources->isEmpty()) {
            $this->warn('No sources configured.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'tab', 'event', 'on', 'to review', 'last run', 'result'],
            $sources->map(fn (SheetSource $s) => [
                $s->id,
                $s->tab_name,
                $s->event?->event_name ?? '—',
                $s->enabled ? 'yes' : 'no',
                $this->reviewCell($s),
                $s->last_synced_at?->diffForHumans() ?? 'never',
                match ($s->last_status) {
                    SheetSource::STATUS_FAILED => 'FAILED: '.$s->last_error,
                    SheetSource::STATUS_PREVIEW => "would +{$s->last_rows_created} ~{$s->last_rows_updated}",
                    SheetSource::STATUS_OK => "+{$s->last_rows_created} ~{$s->last_rows_updated} skip {$s->last_rows_skipped}",
                    default => 'never run',
                },
            ])->all()
        );

        return self::SUCCESS;
    }

    /**
     * Edits and removals read very differently: one is a batch to approve, the
     * other a question about deleting a location. "0 waiting" next to a tab
     * holding a removal would say nothing is happening when something is.
     */
    protected function reviewCell(SheetSource $source): string
    {
        $parts = [];

        if ($pending = $source->pendingChangeCount()) {
            $parts[] = $pending.' waiting';
        }

        if ($missing = $source->missingCount()) {
            $parts[] = $missing.' removed';
        }

        return $parts === [] ? '—' : implode(', ', $parts);
    }
}
