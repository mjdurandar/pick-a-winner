<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks how many per-location child jobs are still outstanding for a manual run.
     * When the counter reaches 0, the last child flips the run to "completed". Nullable
     * because the inline (scheduled) path finalises itself and never uses the counter.
     */
    public function up(): void
    {
        Schema::table('mailchimp_auto_import_runs', function (Blueprint $table) {
            $table->unsignedInteger('pending_locations')->nullable()->after('locations_skipped');
        });
    }

    public function down(): void
    {
        Schema::table('mailchimp_auto_import_runs', function (Blueprint $table) {
            $table->dropColumn('pending_locations');
        });
    }
};
