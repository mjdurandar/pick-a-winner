<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ExportLog extends Model
{
    protected $fillable = [
        'category',
        'user_id',
        'user_name_snapshot',
        'user_email_snapshot',
        'route_name',
        'method',
        'url',
        'target_type',
        'target_id',
        'params',
        'ip_address',
        'user_agent',
        'row_count',
        'file_name',
        'status',
    ];

    protected $casts = [
        'params' => 'array',
        'row_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record a Pick a Winner draw-access attempt (the password/unlock step).
     *
     * Captures who/what/when/where so admins can audit who accessed a draw,
     * from which IP, and at what time — including failed (denied) attempts.
     * Never throws: logging must not break the draw flow.
     *
     * @param  string  $status  'success' (granted) or 'failed' (denied)
     * @param  array   $params  contextual data (event/location ids + names) — no secrets
     */
    public static function recordDrawAccess(
        Request $request,
        string $status,
        ?string $targetType = null,
        ?string $targetId = null,
        array $params = []
    ): void {
        try {
            $user = Auth::user();

            self::create([
                'category' => 'pickawinner_access',
                'user_id' => $user?->id,
                'user_name_snapshot' => $user?->name,
                'user_email_snapshot' => $user?->email,
                'route_name' => $request->route()?->getName(),
                'method' => $request->method(),
                'url' => substr($request->fullUrl(), 0, 2048),
                'target_type' => $targetType,
                'target_id' => $targetId,
                'params' => $params,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 1024),
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to record Pick a Winner draw access: ' . $e->getMessage());
        }
    }
}
