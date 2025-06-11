<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;

class ProcessQueueWorker extends Command
{
    protected $signature = 'queue:process';
    protected $description = 'Process queued jobs (hosting-friendly version)';

    public function handle()
    {
        $this->info('Starting queue processing...');
        Log::info('Queue processing started');

        try {
            // Get the queue worker instance
            $worker = app(Worker::class);

            // Set worker options
            $options = new WorkerOptions(
                maxJobs: 50,          // Process up to 50 jobs
                maxTime: 60,          // Run for max 60 seconds
                memory: 128,          // Stop if memory exceeds 128MB
                sleep: 2,             // Sleep 2 seconds when no jobs
                maxTries: 3,          // Retry failed jobs up to 3 times
                force: false,
                stopWhenEmpty: true   // Stop when queue is empty
            );

            // Process jobs from the default queue
            $worker->daemon(
                'database',           // Connection
                'default',            // Queue
                $options
            );

            $this->info('Queue processing completed');
            Log::info('Queue processing completed successfully');
            
        } catch (\Exception $e) {
            $this->error('Queue processing failed: ' . $e->getMessage());
            Log::error('Queue processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
} 