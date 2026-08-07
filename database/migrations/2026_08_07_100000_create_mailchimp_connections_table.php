<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailchimp_connections', function (Blueprint $table) {
            $table->id();

            // 'anz' | 'usa' — the same account key used by sms_campaigns.account and
            // mailchimp_import_logs.mailchimp_account. One OAuth connection per account.
            $table->string('account')->unique();

            // Encrypted at rest by the model's 'encrypted' cast, so this holds
            // ciphertext and is far longer than the raw token.
            $table->text('access_token');

            // Datacenter prefix (e.g. us14) read from the OAuth metadata endpoint.
            // Every subsequent API call is built against it, so a connection without
            // one is unusable.
            $table->string('datacenter');

            $table->string('mailchimp_account_id')->nullable();
            $table->string('mailchimp_account_name')->nullable();

            // active | needs_reconnect — set to needs_reconnect when Mailchimp
            // rejects the token mid-operation so the settings page can surface it.
            $table->string('status')->default('active');

            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailchimp_connections');
    }
};
