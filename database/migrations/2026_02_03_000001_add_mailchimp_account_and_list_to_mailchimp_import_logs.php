<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailchimp_import_logs', function (Blueprint $table) {
            $table->string('mailchimp_account', 32)->nullable()->after('source');
            $table->string('list_id', 64)->nullable()->after('mailchimp_account');
            $table->string('list_name', 255)->nullable()->after('list_id');
        });
    }

    public function down(): void
    {
        Schema::table('mailchimp_import_logs', function (Blueprint $table) {
            $table->dropColumn(['mailchimp_account', 'list_id', 'list_name']);
        });
    }
};
