<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailchimp_import_rows', function (Blueprint $table) {
            $table->id();

            $table->foreignId('import_id')->constrained('mailchimp_imports')->cascadeOnDelete();

            // 1-based row number in the original file, excluding the header, so the
            // exported report can be lined up against what the admin uploaded.
            $table->unsignedInteger('row_number');

            // Full addresses live here and in the downloadable report only; anything
            // written to the application log is masked.
            $table->string('email')->nullable();

            // Dry-run outcomes (will_subscribe, already_member, blocked_*) plus the
            // post-run outcomes subscribed and failed.
            $table->string('outcome');
            $table->text('detail')->nullable();
            $table->unsignedSmallInteger('mailchimp_status_code')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->timestamps();

            $table->index('import_id');
            $table->index(['import_id', 'outcome']);

            // One record per file row. Makes the classifier and the import job safe to
            // re-run: a resumed job upserts rather than duplicating.
            $table->unique(['import_id', 'row_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailchimp_import_rows');
    }
};
