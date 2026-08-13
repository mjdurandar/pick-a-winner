<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailchimp_imports', function (Blueprint $table) {
            // Stamped by the job itself, not at dispatch. The status is claimed before
            // the job is queued, so it cannot tell a dry run nobody has picked up from
            // one that is part way through reading a 275,000-member audience — and the
            // preview page has to wait patiently for the second while failing fast on
            // the first.
            $table->timestamp('dry_run_started_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        Schema::table('mailchimp_imports', function (Blueprint $table) {
            $table->dropColumn('dry_run_started_at');
        });
    }
};
