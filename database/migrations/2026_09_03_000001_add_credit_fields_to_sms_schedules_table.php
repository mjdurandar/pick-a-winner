<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sms_schedules', function (Blueprint $table) {
            // What the sheet's Data Count column said — the estimate to cost a
            // row before Mailchimp reports a real recipient_count.
            $table->unsignedInteger('sheet_data_count')->nullable()->after('recipient_count');
            // Mailchimp's own count of SMS segments per message (estimated_segments
            // from the content call). Credits = recipients × this.
            $table->unsignedTinyInteger('message_segments')->nullable()->after('sheet_data_count');
        });
    }

    public function down(): void
    {
        Schema::table('sms_schedules', function (Blueprint $table) {
            $table->dropColumn(['sheet_data_count', 'message_segments']);
        });
    }
};
