<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_resubscribe_attempts', function (Blueprint $table) {
            $table->id();

            // The event is always known; the location only once the user has picked
            // one, which happens after the email check on the sign-up form.
            $table->foreignId('event_id')->nullable()->constrained('events')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();

            $table->string('email')->index();

            $table->string('mailchimp_account')->nullable();
            $table->string('list_id')->nullable();
            $table->string('list_name')->nullable();

            // resubscribed, blocked_compliance or failed. Until this table existed
            // only successes were counted, so there was no way to answer "who could
            // we not bring back" — the refusals were discarded.
            $table->string('outcome')->index();

            // Mailchimp's own explanation, kept verbatim for the report.
            $table->text('detail')->nullable();

            $table->timestamps();

            // Reports group by film, which is reached through event -> film_id.
            $table->index(['event_id', 'outcome']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_resubscribe_attempts');
    }
};
