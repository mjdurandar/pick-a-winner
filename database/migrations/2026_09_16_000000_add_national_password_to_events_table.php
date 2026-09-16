<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Password that unlocks the National Tour Wide draw (the draw across
     * every location of an event). Deliberately separate from the per-location
     * passwords: only the tour-wide host should be able to draw from the whole
     * pool. Set/changed by an admin on the Events page.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('national_password')->nullable()->after('instructions_password');
        });

        // Backfill so every existing event has a working tour-wide password
        // (the admin can see and change it on the Events page).
        DB::table('events')->whereNull('national_password')->orderBy('id')->each(function ($event) {
            DB::table('events')
                ->where('id', $event->id)
                ->update(['national_password' => strtoupper(Str::random(6))]);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('national_password');
        });
    }
};
