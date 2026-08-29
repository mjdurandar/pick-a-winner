<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The OAuth connection to the Google account that can read the shared master
 * sheet.
 *
 * Both tokens are encrypted at rest and hidden from serialization — these models
 * are handed to Inertia, so anything not hidden ends up in the page props.
 * Nothing outside the server ever needs them.
 */
class GoogleConnection extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_NEEDS_RECONNECT = 'needs_reconnect';

    /** The only connection slot in use today. */
    public const PURPOSE_SHEETS = 'sheets';

    protected $fillable = [
        'purpose',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'google_account_email',
        'status',
        'connected_by_user_id',
        'connected_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'token_expires_at' => 'datetime',
        'connected_at' => 'datetime',
    ];

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
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
     * True when the access token has expired or is close enough that a call
     * started now could outlive it. The minute of headroom is what stops a sync
     * from failing on a token that was valid when the request was built.
     */
    public function tokenIsStale(): bool
    {
        return $this->token_expires_at === null
            || $this->token_expires_at->isBefore(now()->addMinute());
    }

    /**
     * Called when Google rejects the refresh token — the user revoked access, or
     * changed their password on a testing-mode OAuth client. Syncs check this
     * before running and stop rather than burning retries against a credential
     * that will not come back on its own.
     */
    public function markNeedsReconnect(): void
    {
        $this->forceFill(['status' => self::STATUS_NEEDS_RECONNECT])->save();
    }
}
