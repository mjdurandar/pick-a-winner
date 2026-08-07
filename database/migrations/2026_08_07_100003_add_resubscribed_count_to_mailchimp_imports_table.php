<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contacts already in the audience as unsubscribed, cleaned or archived are
     * recovered with an individual PATCH, mirroring what the WIN sign-up form
     * already does. They are counted apart from first-time subscribes so the report
     * shows how many existing records were revived rather than hiding them in the
     * subscribed total.
     */
    public function up(): void
    {
        Schema::table('mailchimp_imports', function (Blueprint $table) {
            $table->unsignedInteger('resubscribed_count')->default(0)->after('subscribed_count');
        });
    }

    public function down(): void
    {
        Schema::table('mailchimp_imports', function (Blueprint $table) {
            $table->dropColumn('resubscribed_count');
        });
    }
};
