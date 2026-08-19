<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retires the manual Mailchimp import feature set.
 *
 * The MC Logs page, the per-location manual imports, the bulk "Import All" and the
 * scheduled "auto-import finished locations" run are all gone; the only Mailchimp
 * import left in the app is the CSV wizard, and the only automation is the
 * Mailchimp Auto-Sync settings. Nothing reads these tables or columns any more.
 *
 * This drops import history permanently. The per-location newsletter resubscribe
 * counts that used to live on mailchimp_import_logs.total_resubscribed are now
 * read from newsletter_resubscribe_attempts, which keeps a row per attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('mailchimp_import_logs');
        Schema::dropIfExists('mailchimp_auto_import_runs');

        Schema::table('events', function (Blueprint $table) {
            foreach ([
                'auto_import_enabled',
                'auto_import_list_id',
                'auto_import_list_name',
                'auto_import_account',
            ] as $column) {
                if (Schema::hasColumn('events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    /**
     * The events columns come back empty and the two tables come back as bare
     * schema — the rows they held are not recoverable.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('auto_import_enabled')->default(false);
            $table->string('auto_import_list_id', 64)->nullable();
            $table->string('auto_import_list_name')->nullable();
            $table->string('auto_import_account', 32)->nullable();
        });
    }
};
