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
        Schema::table('locations', function (Blueprint $table) {
            $table->string('cinema_contact')->nullable()->after('category');
            $table->integer('number_of_screenings')->nullable()->after('cinema_contact');
            $table->string('status')->nullable()->after('number_of_screenings');
            $table->string('ticketing_type')->nullable()->after('status');
            $table->string('booked_by')->nullable()->after('ticketing_type');
            $table->date('date_booking_confirmed')->nullable()->after('booked_by');
            $table->string('film_format')->nullable()->after('date_booking_confirmed');
            $table->boolean('dcp_trailer_sent')->default(false)->after('film_format');
            $table->boolean('media_kit_sent')->default(false)->after('dcp_trailer_sent');
            $table->boolean('dcp_sent')->default(false)->after('media_kit_sent');
            $table->text('specific_deliverable_requests')->nullable()->after('dcp_sent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn([
                'cinema_contact',
                'number_of_screenings',
                'status',
                'ticketing_type',
                'booked_by',
                'date_booking_confirmed',
                'film_format',
                'dcp_trailer_sent',
                'media_kit_sent',
                'dcp_sent',
                'specific_deliverable_requests',
            ]);
        });
    }
};
