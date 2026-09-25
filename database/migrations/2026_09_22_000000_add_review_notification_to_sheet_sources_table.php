<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sheet_sources', function (Blueprint $table) {
            // The scheduler re-reads every tab every five minutes and rewrites the
            // parked batch each time, so "there are changes waiting" is true on
            // every run until someone approves them. The digest is what the last
            // alert was about; a run only mails when the batch actually differs
            // from it, which turns 288 identical emails a day into one.
            $table->string('review_notified_digest', 64)->nullable()->after('approved_by_user_id');
            $table->timestamp('review_notified_at')->nullable()->after('review_notified_digest');
        });
    }

    public function down(): void
    {
        Schema::table('sheet_sources', function (Blueprint $table) {
            $table->dropColumn(['review_notified_digest', 'review_notified_at']);
        });
    }
};
