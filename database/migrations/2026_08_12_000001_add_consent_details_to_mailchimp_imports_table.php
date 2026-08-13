<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailchimp_imports', function (Blueprint $table) {
            // How the opt-in was collected, in the terms Mailchimp Support asks for
            // when a contact it holds in a compliance state has to be restored on
            // documented consent: the method, where and when it was gathered, and the
            // wording people agreed to. consent_source stays as the short label.
            $table->json('consent_details')->nullable()->after('consent_source');
        });
    }

    public function down(): void
    {
        Schema::table('mailchimp_imports', function (Blueprint $table) {
            $table->dropColumn('consent_details');
        });
    }
};
