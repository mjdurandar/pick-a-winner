<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('mailchimp_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->onDelete('cascade');
            $table->string('email_address')->nullable();
            $table->string('status'); // Success or Failed
            $table->text('error_message')->nullable();
            $table->json('tags')->nullable();
            $table->timestamps();

            // Add index for faster queries
            $table->index(['location_id', 'created_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('mailchimp_logs');
    }
}; 