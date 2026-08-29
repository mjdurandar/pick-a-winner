<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheet_sources', function (Blueprint $table) {
            $table->id();

            // One row per tab an admin has chosen to sync. The ANZ and USA master
            // schedules are separate spreadsheets, and each holds ~20 per-film
            // tabs, so the spreadsheet id is stored per source rather than in a
            // single .env value.
            $table->string('spreadsheet_id');
            $table->string('tab_name');

            // Shown beside the tab name so a source is recognisable without opening
            // Drive. Refreshed whenever the tab picker is loaded for this
            // spreadsheet, which is when the title is already to hand.
            $table->string('spreadsheet_title')->nullable();

            // 'anz' | 'usa' — matches the account keys already used across the
            // Mailchimp side of this application.
            $table->string('region')->nullable();

            // The event this tab feeds. Explicit rather than matched on the sheet's
            // "Film" column, because that column is blank on a meaningful number of
            // rows and identical film names recur across years.
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();

            // Unchecked sources stay configured but are skipped by the scheduler,
            // so a tab can be paused without losing its event mapping.
            $table->boolean('enabled')->default(true);

            // Observability for the scheduled run — the settings screen shows these
            // so a silently failing sync is visible without opening the log.
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('last_rows_created')->default(0);
            $table->unsignedInteger('last_rows_updated')->default(0);
            $table->unsignedInteger('last_rows_skipped')->default(0);

            $table->timestamps();

            // The same tab must not be configured twice, or two sources would
            // race to write the same locations.
            $table->unique(['spreadsheet_id', 'tab_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_sources');
    }
};
