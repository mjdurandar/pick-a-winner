<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('export_logs', function (Blueprint $table) {
            // Distinguishes the kind of activity: 'export' (data exports) or
            // 'pickawinner_access' (a host unlocking a Pick a Winner draw).
            $table->string('category', 40)->default('export')->after('id');
            $table->index(['category', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('export_logs', function (Blueprint $table) {
            $table->dropIndex(['category', 'created_at']);
            $table->dropColumn('category');
        });
    }
};
