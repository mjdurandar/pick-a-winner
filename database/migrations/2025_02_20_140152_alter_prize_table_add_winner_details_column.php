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
        Schema::table('prizes', function (Blueprint $table) {
            $table->string('winner_email')->nullable();
            $table->string('winner_mobile_number')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prizes', function (Blueprint $table) {
            $table->dropColumn('winner_email');
            $table->dropColumn('winner_mobile_number');
        });
    }
};
