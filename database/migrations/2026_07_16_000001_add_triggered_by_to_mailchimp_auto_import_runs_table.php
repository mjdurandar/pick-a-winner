<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Runs can now be started two ways: the daily schedule, or the "Run finished screenings now"
     * button on the import logs page. Both land in the same table, so record which one started it.
     */
    public function up(): void
    {
        Schema::table('mailchimp_auto_import_runs', function (Blueprint $table) {
            $table->string('triggered_by', 16)->default('schedule')->after('dry_run');
            $table->foreignId('triggered_by_user_id')->nullable()->after('triggered_by');
        });
    }

    public function down(): void
    {
        Schema::table('mailchimp_auto_import_runs', function (Blueprint $table) {
            $table->dropColumn(['triggered_by', 'triggered_by_user_id']);
        });
    }
};
