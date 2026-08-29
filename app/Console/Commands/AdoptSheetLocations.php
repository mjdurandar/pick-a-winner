<?php

namespace App\Console\Commands;

use App\Models\SheetSource;
use App\Services\SheetSyncService;
use Illuminate\Console\Command;

/**
 * One-off: mark the locations a tab already matches as belonging to that tab.
 *
 * Locations created before locations.sheet_source_id existed carry no
 * provenance, so the sync cannot tell them from ones typed in by hand and will
 * never report them as dropped from the sheet. Running this once per source
 * closes that gap. It is safe to run again — a location already claimed by a
 * tab is left alone.
 */
class AdoptSheetLocations extends Command
{
    protected $signature = 'sheets:adopt
                            {--source= : Adopt for one source id instead of every enabled one}';

    protected $description = 'Stamp existing locations with the sheet tab that already matches them';

    public function handle(SheetSyncService $sync): int
    {
        $sources = SheetSource::query()
            ->when($this->option('source'), fn ($q, $id) => $q->whereKey($id))
            ->when(! $this->option('source'), fn ($q) => $q->enabled())
            ->get();

        if ($sources->isEmpty()) {
            $this->warn('No sources to adopt for.');

            return self::SUCCESS;
        }

        foreach ($sources as $source) {
            try {
                $adopted = $sync->adopt($source);
            } catch (\Throwable $e) {
                $this->error("  {$source->tab_name}: {$e->getMessage()}");

                continue;
            }

            $this->line("  {$source->tab_name}: adopted {$adopted} location".($adopted === 1 ? '' : 's'));
        }

        return self::SUCCESS;
    }
}
