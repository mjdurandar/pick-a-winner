<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An OAuth connection to one Mailchimp account (anz | usa).
 *
 * The access token is encrypted at rest and hidden from serialization — these
 * models are handed to Inertia, so anything not hidden ends up in the page
 * props. Nothing outside the server ever needs the token.
 */
class MailchimpConnection extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_NEEDS_RECONNECT = 'needs_reconnect';

    protected $fillable = [
        'account',
        'access_token',
        'datacenter',
        'mailchimp_account_id',
        'mailchimp_account_name',
        'status',
        'connected_by_user_id',
        'connected_at',
    ];

    protected $hidden = [
        'access_token',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'connected_at' => 'datetime',
    ];

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    public function imports(): HasMany
    {
        return $this->hasMany(MailchimpImport::class, 'account', 'account');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function needsReconnect(): bool
    {
        return $this->status === self::STATUS_NEEDS_RECONNECT;
    }

    /**
     * Called when Mailchimp rejects the token mid-operation. Running jobs check
     * this before each batch and stop rather than burning retries against a
     * credential that will not come back on its own.
     */
    public function markNeedsReconnect(): void
    {
        $this->forceFill(['status' => self::STATUS_NEEDS_RECONNECT])->save();
    }

    /**
     * Every API call is built against the datacenter returned by the OAuth
     * metadata endpoint — the token alone is not enough to address the account.
     */
    public function apiBaseUrl(): string
    {
        return "https://{$this->datacenter}.api.mailchimp.com/3.0";
    }
}
