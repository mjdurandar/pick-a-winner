<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailchimp_import_logs', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
        });

        Schema::table('mailchimp_import_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable()->change();
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('mailchimp_import_logs', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
        });

        Schema::table('mailchimp_import_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('location_id')->nullable(false)->change();
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('cascade');
        });
    }
};
