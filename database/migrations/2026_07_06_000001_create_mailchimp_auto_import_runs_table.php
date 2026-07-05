<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailchimp_auto_import_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('ran_at');
            $table->unsignedInteger('days_threshold')->default(4);
            $table->boolean('dry_run')->default(false);
            $table->unsignedInteger('events_processed')->default(0);
            $table->unsignedInteger('locations_imported')->default(0);
            $table->unsignedInteger('locations_skipped')->default(0);
            $table->unsignedInteger('total_new')->default(0);
            $table->unsignedInteger('total_updated')->default(0);
            $table->unsignedInteger('total_errors')->default(0);
            // Per-location breakdown of what was imported/skipped this run.
            $table->json('details')->nullable();
            // 'running' while the queued job works, then 'completed' or 'failed'.
            $table->string('status', 32)->default('running');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index('ran_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailchimp_auto_import_runs');
    }
};
