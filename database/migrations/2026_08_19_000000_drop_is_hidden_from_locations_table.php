<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The master sheet is the source of truth for which locations exist, so a
     * location taken off it is deleted rather than hidden. Hiding was meant to
     * protect the attendees of a removed screening, but only the master sheet ever
     * filtered on the flag — the sign-up form, the draws and the reports all went on
     * showing the hidden location, so the replacement the admin added next appeared
     * beside it as a duplicate. Deleting is now confirmed up front instead, with the
     * attendee counts spelled out, and this flag has nothing left to do.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('locations', 'is_hidden')) {
            return;
        }

        // Rows a previous delete hid rather than removed. They are un-hidden rather
        // than deleted: their attendee data is still real, and un-hiding puts them
        // back on the master sheet where an admin can decide, with the counts in
        // front of them, whether to delete them for good. Named in the log first,
        // since dropping the column is the last record of which ones these were.
        $stranded = DB::table('locations')
            ->where('is_hidden', true)
            ->get(['id', 'event_id', 'name', 'date']);

        if ($stranded->isNotEmpty()) {
            Log::warning('Un-hiding locations left over from the is_hidden flag. These are back on the master sheet; delete them there if they should not exist.', [
                'count' => $stranded->count(),
                'locations' => $stranded->toArray(),
            ]);

            DB::table('locations')->where('is_hidden', true)->update(['is_hidden' => false]);
        }

        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('is_hidden');
        });
    }

    /**
     * The column comes back empty — which locations were hidden is not recoverable
     * from the schema, only from the log line written above.
     */
    public function down(): void
    {
        if (Schema::hasColumn('locations', 'is_hidden')) {
            return;
        }

        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false)->after('category');
        });
    }
};
