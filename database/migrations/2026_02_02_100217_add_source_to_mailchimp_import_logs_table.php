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
        Schema::table('mailchimp_import_logs', function (Blueprint $table) {
            $table->string('source', 32)->default('signup_form')->after('tags'); // 'signup_form' | 'ticket_data'
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mailchimp_import_logs', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
