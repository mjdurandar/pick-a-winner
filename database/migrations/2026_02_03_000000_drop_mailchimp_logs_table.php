<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations. Drop mailchimp_logs; all logging now uses mailchimp_import_logs.
     */
    public function up()
    {
        Schema::dropIfExists('mailchimp_logs');
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::create('mailchimp_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->onDelete('cascade');
            $table->string('email_address')->nullable();
            $table->string('status');
            $table->text('error_message')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();
            $table->index(['location_id', 'created_at']);
        });
    }
};
