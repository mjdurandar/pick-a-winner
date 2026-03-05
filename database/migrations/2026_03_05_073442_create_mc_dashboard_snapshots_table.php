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
        Schema::create('mc_dashboard_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('account'); // 'anz' or 'usa'
            $table->string('period_label'); // e.g. 'March', 'Week 10'
            $table->date('snapshot_date');
            $table->json('audiences')->nullable();
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('au_total')->default(0);
            $table->unsignedBigInteger('us_total')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mc_dashboard_snapshots');
    }
};
