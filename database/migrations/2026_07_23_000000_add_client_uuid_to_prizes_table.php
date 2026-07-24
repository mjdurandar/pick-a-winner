<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds a client-generated uuid to prizes so the Pick-a-Winner pages can
     * save winners offline and retry syncing without creating duplicates.
     */
    public function up(): void
    {
        Schema::table('prizes', function (Blueprint $table) {
            $table->string('client_uuid', 64)->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prizes', function (Blueprint $table) {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn('client_uuid');
        });
    }
};
