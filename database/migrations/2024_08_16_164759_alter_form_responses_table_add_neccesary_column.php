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
        Schema::table('form_responses', function (Blueprint $table) {
            $table->string('email')->unique()->nullable()->after('responses');
            $table->string('phone')->nullable()->after('email');
            $table->string('first_name')->nullable()->after('phone');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('gender')->nullable()->after('last_name');
            $table->string('age')->nullable()->after('gender');
            $table->string('event_location')->nullable()->after('age');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('form_responses', function (Blueprint $table) {
            $table->dropColumn('email');
            $table->dropColumn('phone');
            $table->dropColumn('first_name');
            $table->dropColumn('last_name');
            $table->dropColumn('gender');
            $table->dropColumn('age');
            $table->dropColumn('event_location');
        });
    }
};
