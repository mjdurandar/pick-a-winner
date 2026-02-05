<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailchimp_import_logs', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('has_import_file');
        });
    }

    public function down(): void
    {
        Schema::table('mailchimp_import_logs', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};
