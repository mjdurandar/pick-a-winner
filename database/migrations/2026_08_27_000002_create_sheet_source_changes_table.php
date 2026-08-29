<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sheet_source_changes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sheet_source_id')->constrained('sheet_sources')->cascadeOnDelete();

            // Rewritten wholesale on every dry run — this is a view of what the
            // sheet says right now, never an append-only history.
            $table->unsignedInteger('sheet_row');

            // create | update | unchanged | skipped
            $table->string('action');

            // The location this row matched, for update and unchanged.
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();

            // Human-readable identity of the screening, so the review screen can
            // label a create that has no location row yet.
            $table->string('label')->nullable();

            // The full attribute payload the row maps to.
            $table->json('payload')->nullable();

            // field => [before, after], populated for updates only. This is what
            // the review screen actually shows.
            $table->json('diff')->nullable();

            // Why a row was not treated as a screening.
            $table->string('skip_reason')->nullable();

            $table->timestamps();

            $table->index(['sheet_source_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sheet_source_changes');
    }
};
