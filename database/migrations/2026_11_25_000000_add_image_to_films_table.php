<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A picture for each website, so the Websites page can show them as cards.
     * Optional: a website without one shows the default image.
     */
    public function up(): void
    {
        Schema::table('films', function (Blueprint $table) {
            $table->string('image')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('films', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};
