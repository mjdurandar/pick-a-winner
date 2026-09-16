<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('film_site_check_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('film_site_check_id')->constrained('film_site_checks')->cascadeOnDelete();

            // clean | warnings | errors | failed
            $table->string('status');
            $table->text('error')->nullable();

            // schedule | manual | cli, and who pressed the button for manual runs.
            $table->string('trigger')->default('schedule');
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('matched')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->unsignedInteger('warnings')->default(0);
            $table->unsignedInteger('notices')->default(0);

            // Compared with the previous run, so the log reads as a timeline of
            // what broke and what got fixed rather than the same list every hour.
            $table->unsignedInteger('new_issues')->default(0);
            $table->unsignedInteger('resolved_issues')->default(0);

            // Every compared row with its severity, and what the site returned
            // (session counts, resolved taxonomy ids) for diagnosing a bad run.
            $table->json('items')->nullable();
            $table->json('meta')->nullable();

            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamps();

            $table->index(['film_site_check_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('film_site_check_runs');
    }
};
