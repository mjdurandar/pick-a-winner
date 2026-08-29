<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A 'missing' entry describes a location the sheet no longer mentions, so there
 * is no row number to record for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheet_source_changes', function (Blueprint $table) {
            $table->unsignedInteger('sheet_row')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sheet_source_changes', function (Blueprint $table) {
            $table->unsignedInteger('sheet_row')->nullable(false)->change();
        });
    }
};
