<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailchimp_imports', function (Blueprint $table) {
            // Off by default: an import that used to skip contacts already in the
            // audience must keep skipping them unless someone asks otherwise.
            $table->boolean('update_existing')->default(false)->after('tag');
            $table->unsignedInteger('updated_count')->default(0)->after('resubscribed_count');
        });

        Schema::table('mailchimp_import_rows', function (Blueprint $table) {
            // The status Mailchimp reported during the dry run. Echoed back on an
            // update so the run can never change someone's standing: a contact
            // waiting to confirm a double opt-in stays pending rather than being
            // confirmed on their behalf.
            $table->string('existing_status')->nullable()->after('detail');
        });
    }

    public function down(): void
    {
        Schema::table('mailchimp_imports', function (Blueprint $table) {
            $table->dropColumn(['update_existing', 'updated_count']);
        });

        Schema::table('mailchimp_import_rows', function (Blueprint $table) {
            $table->dropColumn('existing_status');
        });
    }
};
