<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailchimp_import_logs', function (Blueprint $table) {
            $table->string('status', 32)->default('import')->after('list_name'); // 'import' | 'reimport'
            $table->json('failed_rows')->nullable()->after('errors'); // [{ email_address, first_name, last_name, ..., error_message }, ...]
        });
    }

    public function down(): void
    {
        Schema::table('mailchimp_import_logs', function (Blueprint $table) {
            $table->dropColumn(['status', 'failed_rows']);
        });
    }
};
