<?php

namespace App\Console;

use App\Jobs\McSnapshotJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        Commands\ProcessQueueWorker::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Run the queue processor every minute (hosting-friendly: process then exit)
        $schedule->command('queue:work database --stop-when-empty --max-jobs=50 --max-time=60')
                ->everyMinute()
                ->withoutOverlapping();

        // Snapshot Mailchimp audience counts every Friday at 9am
        $schedule->job(new McSnapshotJob)->weeklyOn(5, '09:00');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
} 