<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_schedules', function (Blueprint $table) {
            $table->id();

            // One paste of the planning sheet is one batch. Rows keep their batch
            // so the screen can show "what I pasted just now" apart from history.
            $table->uuid('batch_id')->index();

            // The planning-sheet columns, kept as pasted so a row can always be
            // traced back to the line it came from.
            $table->string('film_tour');
            $table->string('location');
            $table->unsignedSmallInteger('year')->nullable();
            $table->date('screening_date')->nullable();
            $table->string('trigger_event')->nullable();
            $table->string('sheet_status')->nullable();
            $table->string('tag');
            $table->text('sms_text');
            $table->string('link', 1024)->nullable();

            // When it goes. The sheet only carries a date; the time and zone are
            // batch defaults the admin can change per row. send_at_utc is what
            // Mailchimp is actually told.
            $table->date('send_date');
            $table->time('send_time');
            $table->string('timezone', 64);
            $table->dateTime('send_at_utc')->nullable();

            // What the tag column resolved to in Mailchimp. A plain SHOW tag is a
            // static segment and is used directly; "A & B" becomes a saved
            // segment matching both, created on demand.
            $table->json('tags_resolved')->nullable();
            $table->unsignedBigInteger('segment_id')->nullable();
            $table->string('segment_name')->nullable();

            // The campaign as it will be (or was) created.
            $table->string('campaign_name');
            $table->text('message_body')->nullable();
            $table->string('mailchimp_campaign_id', 64)->nullable()->index();
            $table->unsignedInteger('recipient_count')->nullable();

            // See SmsSchedule::STATUS_*. issues is a list of {level, text} found
            // while resolving; an 'error' level keeps the row out of scheduling.
            $table->string('status', 32)->index();
            $table->json('issues')->nullable();
            $table->text('error')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Re-pasting the sheet updates the same row rather than adding a twin.
            $table->index(['film_tour', 'location', 'screening_date', 'trigger_event'], 'sms_schedules_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_schedules');
    }
};
