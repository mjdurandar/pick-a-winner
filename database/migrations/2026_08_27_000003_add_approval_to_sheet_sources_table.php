<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheet_sources', function (Blueprint $table) {
            // A newly connected tab syncs into the staging table only. Nothing
            // reaches the locations table until an admin has looked at the first
            // run and approved it; from then on the scheduler applies each run
            // directly. Set back to null to put a tab under review again.
            $table->timestamp('approved_at')->nullable()->after('enabled');
            $table->foreignId('approved_by_user_id')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sheet_sources', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by_user_id');
            $table->dropColumn('approved_at');
        });
    }
};
