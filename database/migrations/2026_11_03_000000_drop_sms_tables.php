<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the SMS scheduler's tables.
 *
 * The scheduler and its short links are gone from the app, so the tables are
 * dead weight. There is no down(): the create migrations went with the feature,
 * and recreating empty tables for a screen that no longer exists would only
 * mislead the next person. Restore from git if the feature ever comes back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('short_links');
        Schema::dropIfExists('sms_schedules');
    }
};
