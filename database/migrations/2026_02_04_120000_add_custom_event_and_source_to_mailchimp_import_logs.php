<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailchimp_import_logs', function (Blueprint $table) {
            $table->string('custom_event_name', 255)->nullable()->after('list_name');
            $table->string('custom_source', 255)->nullable()->after('custom_event_name');
        });
    }

    public function down(): void
    {
        Schema::table('mailchimp_import_logs', function (Blueprint $table) {
            $table->dropColumn(['custom_event_name', 'custom_source']);
        });
    }
};
