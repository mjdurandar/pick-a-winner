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
            $table->json('film_tags')->nullable()->after('audiences');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mc_dashboard_snapshots', function (Blueprint $table) {
            $table->dropColumn('film_tags');
        });
    }
};
