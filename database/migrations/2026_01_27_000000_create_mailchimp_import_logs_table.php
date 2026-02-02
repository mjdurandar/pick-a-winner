<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailchimp_import_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('locations')->onDelete('cascade');
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('total_data')->default(0);
            $table->unsignedInteger('new_contacts')->default(0);
            $table->unsignedInteger('updated_data')->default(0);
            $table->unsignedInteger('data_with_error')->default(0);
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->index(['location_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailchimp_import_logs');
    }
};
