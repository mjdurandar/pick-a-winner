<?php

namespace App\Services;

use App\Models\ShortLink;
use Illuminate\Support\Facades\Config;

/**
 * Makes and resolves the app's own short links (see ShortLink).
 *
 * The public base comes from SHORT_LINK_BASE_URL when set — a short domain
 * pointed at this app keeps SMS well under one segment — and falls back to
 * APP_URL. It is read from config, not the current request, so links built
 * on a laptop still carry the production host once deployed.
 */
class ShortLinkService
{
    /** No 0/O/1/l/I: these get read out loud and typed by hand. */
    protected const ALPHABET = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';

    /** Anything at or under this is already short enough to leave alone. */
    public const LEAVE_ALONE_LENGTH = 40;

    public function baseUrl(): string
    {
        return rtrim((string) (Config::get('services.short_links.base_url') ?: Config::get('app.url')), '/');
    }

    /** Absolute short URL for a destination, reusing an existing code for the same URL. */
    public function shorten(string $url): string
    {
        $url = trim($url);
        $hash = hash('sha256', $url);

        $link = ShortLink::where('url_hash', $hash)->first()
            ?? ShortLink::create(['code' => $this->freshCode(), 'url' => $url, 'url_hash' => $hash]);

        return $this->baseUrl().'/s/'.$link->code;
    }

    /**
     * Replace every long URL in a message with a short one. Short ones (a
     * Bitly link pasted by hand, or one of ours) are left as they are.
     */
    public function shortenLinksIn(string $body): string
    {
        return preg_replace_callback('~https?://[^\s<>"\']+~i', function (array $m) {
            $url = rtrim($m[0], '.,;:!?)');
            $trail = substr($m[0], strlen($url));

            if (strlen($url) <= self::LEAVE_ALONE_LENGTH || str_starts_with($url, $this->baseUrl().'/s/')) {
                return $m[0];
            }

            return $this->shorten($url).$trail;
        }, $body);
    }

    protected function freshCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while (ShortLink::where('code', $code)->exists());

        return $code;
    }
}
