<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            // Durable per-event config for the scheduled "auto-import finished locations" automation.
            $table->boolean('auto_import_enabled')->default(false);
            $table->string('auto_import_list_id', 64)->nullable();
            $table->string('auto_import_list_name')->nullable();
            $table->string('auto_import_account', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'auto_import_enabled',
                'auto_import_list_id',
                'auto_import_list_name',
                'auto_import_account',
            ]);
        });
    }
};
