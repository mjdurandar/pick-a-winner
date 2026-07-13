<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Password that gates the shareable host instruction guide, so the link is
     * private rather than open to anyone who has the URL.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->string('instructions_password')->nullable()->after('event_uuid');
        });

        // Backfill existing events with a random password so every guide is protected.
        DB::table('events')->whereNull('instructions_password')->orderBy('id')->each(function ($event) {
            DB::table('events')
                ->where('id', $event->id)
                ->update(['instructions_password' => strtoupper(Str::random(6))]);
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('instructions_password');
        });
    }
};
