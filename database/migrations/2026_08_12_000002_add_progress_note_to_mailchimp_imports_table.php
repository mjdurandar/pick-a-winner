<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailchimp_imports', function (Blueprint $table) {
            // What the run is doing while it is doing nothing visible. Waiting out
            // the signup form's rate limit takes over a minute per contact and
            // records nothing, which the preview page could only read as a stall —
            // it declared a working import dead twice before this existed.
            $table->string('progress_note')->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('mailchimp_imports', function (Blueprint $table) {
            $table->dropColumn('progress_note');
        });
    }
};
