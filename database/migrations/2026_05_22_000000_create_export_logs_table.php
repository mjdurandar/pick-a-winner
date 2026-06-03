<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name_snapshot')->nullable();
            $table->string('user_email_snapshot')->nullable();
            $table->string('route_name')->nullable();
            $table->string('method', 10);
            $table->string('url', 2048);
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();
            $table->json('params')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('file_name')->nullable();
            $table->string('status', 20)->default('success');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['route_name', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_logs');
    }
};
