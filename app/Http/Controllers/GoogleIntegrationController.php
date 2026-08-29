<?php

namespace App\Http\Controllers;

use App\Exceptions\GoogleOAuthException;
use App\Models\GoogleConnection;
use App\Services\GoogleOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The admin-facing connect / disconnect flow for the Google account the master
 * sheet sync reads as.
 */
class GoogleIntegrationController extends Controller
{
    public function __construct(protected GoogleOAuth $oauth) {}

    /**
     * Send the admin to Google to authorize. The state is held in the session and
     * checked on the way back, so a callback the admin did not start is rejected.
     */
    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->oauth->isConfigured()) {
            return back()->with('error', 'Google OAuth is not configured. Set GOOGLE_OAUTH_CLIENT_ID and GOOGLE_OAUTH_CLIENT_SECRET.');
        }

        $state = $this->oauth->newState();
        $request->session()->put(GoogleOAuth::SESSION_KEY, $state);

        return redirect()->away($this->oauth->authorizeUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expected = $request->session()->pull(GoogleOAuth::SESSION_KEY);

        // hash_equals rather than === so a mismatch cannot be probed by timing.
        if (! $expected || ! is_string($request->query('state')) || ! hash_equals($expected, $request->query('state'))) {
            return redirect()->route('sheetSync.index')
                ->with('error', 'That Google sign-in did not match the one started here. Please try again.');
        }

        if ($request->query('error')) {
            // access_denied is the admin clicking Cancel — not worth an alarming
            // message.
            return redirect()->route('sheetSync.index')->with(
                'error',
                $request->query('error') === 'access_denied'
                    ? 'Google sign-in was cancelled.'
                    : 'Google returned an error: '.$request->query('error')
            );
        }

        $code = $request->query('code');

        if (! is_string($code) || $code === '') {
            return redirect()->route('sheetSync.index')->with('error', 'Google did not return an authorization code.');
        }

        try {
            $tokens = $this->oauth->exchangeCode($code);
        } catch (GoogleOAuthException $e) {
            return redirect()->route('sheetSync.index')->with('error', $e->getMessage());
        }

        GoogleConnection::updateOrCreate(
            ['purpose' => GoogleConnection::PURPOSE_SHEETS],
            [
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'token_expires_at' => now()->addSeconds($tokens['expires_in']),
                'google_account_email' => $this->oauth->accountEmail($tokens['access_token']),
                'status' => GoogleConnection::STATUS_ACTIVE,
                'connected_by_user_id' => $request->user()?->id,
                'connected_at' => now(),
            ]
        );

        return redirect()->route('sheetSync.index')->with('success', 'Google account connected.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $connection = GoogleConnection::where('purpose', GoogleConnection::PURPOSE_SHEETS)->first();

        if (! $connection) {
            return back()->with('error', 'No Google account is connected.');
        }

        // Revoking is best effort — if Google refuses, the local credential is
        // still deleted so the sync stops either way.
        if (! $this->oauth->revoke($connection->refresh_token)) {
            Log::warning('Google connection deleted locally but revoke failed', ['id' => $connection->id]);
        }

        $connection->delete();

        return back()->with('success', 'Google account disconnected.');
    }
}
