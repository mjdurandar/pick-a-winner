<?php

use App\Jobs\DripOutstandingOptInsJob;
use App\Jobs\McSnapshotJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        // NOTE: queue processing is handled by a separate direct Hostinger cron
        // (`artisan queue:work --stop-when-empty --max-time=59` every minute), so it is
        // deliberately NOT scheduled here — doing so would run two overlapping workers.

        // Snapshot Mailchimp audience counts every Friday at 9am
        $schedule->job(new McSnapshotJob)->weeklyOn(5, '09:00');

        // Work through the opt-ins the hosted signup form rate-limited an import out
        // of. Offered hourly; the job decides for itself whether enough time has
        // passed, since only it knows what the form did last time.
        $schedule->job(new DripOutstandingOptInsJob)->hourly()->withoutOverlapping(60);

        // Pull the configured master schedule tabs. Read-only against Google —
        // the connection holds spreadsheets.readonly and cannot write back.
        //
        // Every five minutes. This was hourly, sized for the twenty tabs the sync
        // was expected to watch; with a handful the quota argument is gone — 288
        // reads a day per tab against a limit of 300 a minute — and an admin who
        // fixes a screening wants to see it now, not up to an hour later.
        //
        // Queued rather than inline so one slow tab cannot hold up the rest of the
        // scheduler. The overlap lock is ten minutes: long enough to outlast a
        // normal run, short enough that a crashed one is not still blocking an
        // hour later.
        $schedule->command('sheets:sync --queue')->everyFiveMinutes()->withoutOverlapping(10);
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'log.exports' => \App\Http\Middleware\LogExports::class,
            'pickawinner.access' => \App\Http\Middleware\EnsurePickAWinnerAccess::class,
            'sms.access' => \App\Http\Middleware\EnsureSmsAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
