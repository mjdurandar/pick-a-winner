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
            $table->text('heading')->nullable()->after('event_banner');
            $table->text('privacy_link')->nullable()->after('event_description');
            $table->text('terms_link')->nullable()->after('privacy_link');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sign_up_forms', function (Blueprint $table) {
            $table->dropColumn('heading');
            $table->dropColumn('privacy_link');
            $table->dropColumn('terms_link');
        });
    }
};
