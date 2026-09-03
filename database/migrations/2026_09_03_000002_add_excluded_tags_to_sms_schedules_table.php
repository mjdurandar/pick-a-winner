<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_schedules', function (Blueprint $table) {
            // "SHOW-X exclude INT - Y": the tags after "exclude", resolved. Sent to
            // Mailchimp as excluded_segments on the campaign.
            $table->json('excluded_tags')->nullable()->after('tags_resolved');
        });
    }

    public function down(): void
    {
        Schema::table('sms_schedules', function (Blueprint $table) {
            $table->dropColumn('excluded_tags');
        });
    }
};
