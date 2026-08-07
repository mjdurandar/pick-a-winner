<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where these contacts opted in, in the admin's own words — "Partner signup
     * form, Jan 2026". The checkbox records that someone attested consent; this
     * records what they were attesting to, which is the part an audit actually
     * needs.
     */
    public function up(): void
    {
        Schema::table('mailchimp_imports', function (Blueprint $table) {
            $table->string('consent_source')->nullable()->after('consent_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('mailchimp_imports', function (Blueprint $table) {
            $table->dropColumn('consent_source');
        });
    }
};
