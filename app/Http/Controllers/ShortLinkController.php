<?php

namespace App\Http\Controllers;

use App\Models\ShortLink;
use Illuminate\Http\RedirectResponse;

/** Resolves /s/{code}: count the click, send them on. */
class ShortLinkController extends Controller
{
    public function redirect(string $code): RedirectResponse
    {
        $link = ShortLink::where('code', $code)->firstOrFail();

        // Not a transaction-worthy counter; a lost increment under load is fine.
        $link->increment('clicks', 1, ['last_clicked_at' => now()]);

        return redirect()->away($link->url, 302);
    }
}
