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
        Schema::table('mc_dashboard_snapshots', function (Blueprint $table) {
            $table->json('mag_tags')->nullable()->after('film_tags');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mc_dashboard_snapshots', function (Blueprint $table) {
            $table->dropColumn('mag_tags');
        });
    }
};
