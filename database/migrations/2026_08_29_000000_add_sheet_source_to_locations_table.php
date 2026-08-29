<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which sheet tab a location came from.
 *
 * Without this the sync cannot tell "this screening was dropped from the sheet"
 * apart from "someone added this location by hand on the locations screen" or
 * "this belongs to another tab pointed at the same event" — all three simply
 * look like a location with no matching row. Reporting a removal without it
 * would flag every hand-made location as deleted.
 *
 * Null means "not managed by a sheet", which is also what pressing Keep on a
 * vanished row sets it back to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            // nullOnDelete, not cascade: disconnecting a tab keeps its locations,
            // exactly as SheetSyncController::destroy already promises.
            $table->foreignId('sheet_source_id')
                ->nullable()
                ->after('event_id')
                ->constrained('sheet_sources')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropForeign(['sheet_source_id']);
            $table->dropColumn('sheet_source_id');
        });
    }
};
