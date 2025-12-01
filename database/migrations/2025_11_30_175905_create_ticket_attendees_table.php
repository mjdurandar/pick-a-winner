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
        Schema::create('ticket_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->onDelete('cascade');
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->string('email')->index();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('eventbrite_event_id')->nullable();
            $table->timestamps();

            // Index for faster queries
            $table->index(['location_id', 'email']);
            $table->index(['event_id', 'email']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_attendees');
    }
};
