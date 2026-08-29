<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_connections', function (Blueprint $table) {
            $table->id();

            // Only one Google account is ever connected, but keying by purpose
            // leaves room for a second without a migration. 'sheets' is the slot
            // the master sheet sync reads.
            $table->string('purpose')->unique()->default('sheets');

            // Both encrypted at rest by the model's casts, so these hold
            // ciphertext and are far longer than the raw tokens.
            //
            // Unlike Mailchimp's, a Google access token expires after roughly an
            // hour. The refresh token is the durable credential — Google returns
            // it only on the first consent, so it is never overwritten with null
            // on a later refresh.
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('token_expires_at')->nullable();

            $table->string('google_account_email')->nullable();

            // active | needs_reconnect — set to needs_reconnect when Google
            // rejects the refresh token, which no amount of retrying will fix.
            $table->string('status')->default('active');

            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('connected_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_connections');
    }
};
