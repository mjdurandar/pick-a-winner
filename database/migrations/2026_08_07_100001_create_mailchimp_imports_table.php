<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailchimp_imports', function (Blueprint $table) {
            $table->id();

            $table->string('account')->default('anz')->index();

            // Upload. stored_path is the on-disk CSV, deleted once the run finishes;
            // filename/file_size are snapshotted so the report still reads correctly
            // after the file is gone.
            $table->string('filename');
            $table->string('stored_path')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('original_row_count')->default(0);

            // Target audience. Name is snapshotted for the history list.
            $table->string('audience_id')->nullable();
            $table->string('audience_name')->nullable();
            $table->string('tag')->nullable();

            // CSV header => Mailchimp merge tag. EMAIL is required before a run
            // can leave the configure step.
            $table->json('field_map')->nullable();

            // Read from GET /lists/{id}. Drives both the preview wording and the
            // member status the job sends (pending vs subscribed).
            $table->boolean('double_optin')->default(false);

            // Compliance paper trail: who ticked the opt-in confirmation and when.
            $table->foreignId('consent_confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('consent_confirmed_at')->nullable();

            // pending -> dry_run_complete -> running -> complete, plus failed
            $table->string('status')->default('pending');

            // Index of the last batch confirmed written to Mailchimp. A retry resumes
            // from the next one, so a worker killed mid-run cannot re-send those rows.
            $table->integer('last_batch_index')->nullable();

            $table->unsignedInteger('subscribed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_reason')->nullable();

            // Who ran it — set at upload, before consent is confirmed.
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailchimp_imports');
    }
};
