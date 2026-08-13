<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailchimp_form_relay_runs', function (Blueprint $table) {
            $table->id();

            // One row per attempt at the hosted signup form, which is also one
            // experiment: these columns are the entire evidence base from which the
            // next attempt's pacing is worked out. Mailchimp publishes no limit for
            // that form, so the only way to know it is to keep measuring.
            $table->string('account')->index();

            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            // What this attempt decided to try.
            $table->unsignedInteger('interval_seconds');
            $table->unsignedInteger('planned');

            // What actually happened.
            $table->unsignedInteger('accepted')->default(0);
            $table->boolean('throttled')->default(false);

            // When the next attempt may start, decided from this one's outcome.
            $table->timestamp('next_attempt_at')->nullable();

            $table->timestamps();

            // Every read is "the latest attempt for this account".
            $table->index(['account', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailchimp_form_relay_runs');
    }
};
