<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('sign_up_forms', function (Blueprint $table) {
            $table->string('table_name')->nullable()->after('event_banner');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sign_up_forms', function (Blueprint $table) {
            $table->dropColumn('table_name');
        });
    }
};
