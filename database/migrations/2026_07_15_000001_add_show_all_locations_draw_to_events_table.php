<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Split the single "show all locations" toggle in two: show_all_locations keeps
     * driving the sign-up form, while the Pick a Winner draw gets its own flag.
     * Existing events are backfilled from the old column so behaviour is unchanged.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('show_all_locations_draw')->default(false)->after('show_all_locations');
        });

        DB::table('events')->update([
            'show_all_locations_draw' => DB::raw('show_all_locations'),
        ]);
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('show_all_locations_draw');
        });
    }
};
