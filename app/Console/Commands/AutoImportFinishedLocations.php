<?php

namespace App\Console\Commands;

use App\Jobs\AutoImportFinishedLocationsJob;
use App\Models\Events;
use App\Models\MailchimpAutoImportRun;
use App\Models\MailchimpImportLog;
use App\Models\SignUpForm;
use App\Models\TicketAttendee;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AutoImportFinishedLocations extends Command
{
    protected $signature = 'mailchimp:auto-import-finished
        {--days=4 : Import locations whose screening date is at least this many days in the past}
        {--dry-run : List what would be imported without dispatching or calling Mailchimp}';

    protected $description = 'Auto-import locations whose event finished N+ days ago into Mailchimp (per-event opt-in).';

    public function handle(): int
    {
        $days = max(0, (int) $this->option('days'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::now()->startOfDay()->subDays($days);

        // Only events explicitly opted in AND with a configured audience.
        $events = Events::where('auto_import_enabled', true)
            ->whereNotNull('auto_import_list_id')
            ->where('auto_import_list_id', '!=', '')
            ->whereNotNull('auto_import_account')
            ->where('auto_import_account', '!=', '')
            ->with('locations')
            ->get();

        $items = [];
        $signUpFormCache = [];

        foreach ($events as $event) {
            foreach ($event->locations as $location) {
                if (! $this->isFinished($location->date, $cutoff)) {
                    continue;
                }

                // Skip locations already imported to this audience (avoids daily re-imports).
                // A prior run that fully errored (0 new + 0 updated) is NOT counted as imported, so it retries.
                $priorSuccess = (int) MailchimpImportLog::where('location_id', $location->id)
                    ->where('list_id', $event->auto_import_list_id)
                    ->where('mailchimp_account', $event->auto_import_account)
                    ->sum(DB::raw('new_contacts + updated_data'));
                if ($priorSuccess > 0) {
                    continue;
                }

                if (! $this->hasImportableData($event, $location->id, $signUpFormCache)) {
                    continue;
                }

                $items[] = [
                    'event_id' => (int) $event->id,
                    'location_id' => (int) $location->id,
                    'location_name' => (string) $location->name,
                    'list_id' => (string) $event->auto_import_list_id,
                    'list_name' => $event->auto_import_list_name,
                    'account' => (string) $event->auto_import_account,
                ];
            }
        }

        $eventsProcessed = count(array_unique(array_column($items, 'event_id')));

        $this->info(sprintf(
            '%d location(s) across %d event(s) eligible (finished %d+ days ago, not yet imported).',
            count($items),
            $eventsProcessed,
            $days
        ));

        if (empty($items)) {
            // Still record an (empty) run so the log page shows the automation ran.
            MailchimpAutoImportRun::create([
                'ran_at' => Carbon::now(),
                'days_threshold' => $days,
                'dry_run' => $dryRun,
                'events_processed' => 0,
                'locations_imported' => 0,
                'locations_skipped' => 0,
                'total_new' => 0,
                'total_updated' => 0,
                'total_errors' => 0,
                'details' => [],
                'status' => 'completed',
            ]);

            return self::SUCCESS;
        }

        foreach ($items as $item) {
            $this->line(sprintf('  - [%s] %s → %s (%s)', $item['event_id'], $item['location_name'], $item['list_name'] ?: $item['list_id'], $item['account']));
        }

        if ($dryRun) {
            MailchimpAutoImportRun::create([
                'ran_at' => Carbon::now(),
                'days_threshold' => $days,
                'dry_run' => true,
                'events_processed' => $eventsProcessed,
                'locations_imported' => 0,
                'locations_skipped' => count($items),
                'total_new' => 0,
                'total_updated' => 0,
                'total_errors' => 0,
                'details' => array_map(fn ($i) => [
                    'location_id' => $i['location_id'],
                    'location_name' => $i['location_name'],
                    'event_id' => $i['event_id'],
                    'list_name' => $i['list_name'],
                    'account' => $i['account'],
                    'status' => 'would_import',
                ], $items),
                'status' => 'completed',
            ]);
            $this->warn('Dry run: nothing dispatched, no Mailchimp calls made.');

            return self::SUCCESS;
        }

        $run = MailchimpAutoImportRun::create([
            'ran_at' => Carbon::now(),
            'days_threshold' => $days,
            'dry_run' => false,
            'events_processed' => $eventsProcessed,
            'locations_imported' => 0,
            'locations_skipped' => 0,
            'total_new' => 0,
            'total_updated' => 0,
            'total_errors' => 0,
            'details' => [],
            'status' => 'running',
        ]);

        // Run inline (not queued) so this works on shared hosting with only the schedule:run cron —
        // no separate always-on queue worker required. The daily cadence makes a longer run fine.
        Log::info('Auto-import command: starting inline import', ['run_id' => $run->id, 'locations' => count($items)]);
        AutoImportFinishedLocationsJob::dispatchSync($run->id, $items);

        $run->refresh();
        $this->info(sprintf(
            'Run #%d %s: imported %d, skipped %d (new %d, updated %d, errors %d).',
            $run->id,
            $run->status,
            $run->locations_imported,
            $run->locations_skipped,
            $run->total_new,
            $run->total_updated,
            $run->total_errors
        ));

        return self::SUCCESS;
    }

    /**
     * A location is "finished" when its screening date parses and is on/before the cutoff.
     * `date` is a free-text string that may be blank or 'TBA'.
     */
    private function isFinished(?string $dateStr, Carbon $cutoff): bool
    {
        $dateStr = trim((string) $dateStr);
        if ($dateStr === '' || strtoupper($dateStr) === 'TBA') {
            return false;
        }
        try {
            $d = Carbon::parse($dateStr)->startOfDay();
        } catch (\Throwable $e) {
            return false;
        }

        return $d->lte($cutoff);
    }

    /**
     * True if the location has ticket attendees or sign-up-form rows to import.
     */
    private function hasImportableData(Events $event, int $locationId, array &$signUpFormCache): bool
    {
        if (TicketAttendee::where('location_id', $locationId)->exists()) {
            return true;
        }

        $eventId = (int) $event->id;
        if (! array_key_exists($eventId, $signUpFormCache)) {
            $signUpFormCache[$eventId] = SignUpForm::where('event_id', $eventId)->first();
        }
        $signUpForm = $signUpFormCache[$eventId];

        if ($signUpForm && $signUpForm->table_name && Schema::hasTable($signUpForm->table_name)) {
            return DB::table($signUpForm->table_name)
                ->where('location_id', $locationId)
                ->where('event_id', $eventId)
                ->whereNotNull('email_address')
                ->where('email_address', '!=', '')
                ->exists();
        }

        return false;
    }
}
