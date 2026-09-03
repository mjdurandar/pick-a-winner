<?php

namespace App\Http\Controllers;

use App\Models\Events;
use Illuminate\Http\JsonResponse;

/**
 * Read-only JSON feed consumed by the WordPress plugin.
 *
 * The event_uuid is the shared secret, exactly as it is for the public sign-up
 * form (/form/{event_uuid}) and the host guide (/host-guide/{event_uuid}) — so
 * WordPress needs no login, no token and no CORS config of its own.
 *
 * Nothing here writes. The feed is deliberately one-directional: the app owns
 * the banner, WordPress mirrors it.
 */
class WordPressFeedController extends Controller
{
    /**
     * Banner payload for one event.
     *
     * Lives under /api/* on purpose: Laravel's default CORS paths are api/*,
     * so a browser-side fetch from the WP theme works too, not just the
     * server-side wp_remote_get the plugin uses.
     */
    public function banner(string $event_uuid): JsonResponse
    {
        $event = Events::where('event_uuid', $event_uuid)->firstOrFail();

        return response()->json([
            'event_uuid' => $event->event_uuid,
            'event_name' => $event->event_name,
            'event_country' => $event->event_country,
            // A disabled event still answers, so a connection test never looks
            // like a broken endpoint; the plugin renders nothing when false.
            'is_enabled' => (bool) $event->is_enabled,
            'is_signup_closed' => $event->is_signup_closed,
            'banner_url' => $this->publicAsset($event->event_banner),
            'logo_url' => $this->publicAsset($event->event_logo),
            'signup_url' => route('signup.embed', ['event_uuid' => $event->event_uuid]),
            'updated_at' => optional($event->updated_at)->toIso8601String(),
        ]);
    }

    /**
     * Absolute URL for an uploaded file, or null when the row points at a file
     * that is no longer on disk (WordPress should fall back, not hotlink a 404).
     */
    private function publicAsset(?string $path): ?string
    {
        if (empty($path) || ! file_exists(public_path('storage/'.$path))) {
            return null;
        }

        // Uploaded names keep spaces and parens ("image (9) (1).jpg"), which url()
        // does not encode — a raw space breaks wp_remote_get and media sideload.
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));

        return url('storage/'.$encoded);
    }
}
