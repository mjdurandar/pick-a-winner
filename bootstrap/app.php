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

        // Auto-import locations that finished 4+ days ago (per-event opt-in), every day at 8am.
        $schedule->command('mailchimp:auto-import-finished')->dailyAt('08:00')->withoutOverlapping(10);

        // Work through the opt-ins the hosted signup form rate-limited an import out
        // of. Offered hourly; the job decides for itself whether enough time has
        // passed, since only it knows what the form did last time.
        $schedule->job(new DripOutstandingOptInsJob)->hourly()->withoutOverlapping(60);
    })
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'log.exports' => \App\Http\Middleware\LogExports::class,
            'pickawinner.access' => \App\Http\Middleware\EnsurePickAWinnerAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
