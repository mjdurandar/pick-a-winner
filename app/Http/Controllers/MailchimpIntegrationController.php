<?php

namespace App\Http\Controllers;

use App\Exceptions\MailchimpOAuthException;
use App\Models\MailchimpConnection;
use App\Services\MailchimpAuditLog;
use App\Services\MailchimpOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Settings page for the Mailchimp OAuth connection used by the CSV import
 * feature. Admin only — enforced on the route group.
 *
 * There is deliberately no way to paste an API key here. The static keys in
 * config/services.php belong to the older import paths; this feature only ever
 * acts on a token the account owner granted through Mailchimp's own login.
 */
class MailchimpIntegrationController extends Controller
{
    public function __construct(protected MailchimpOAuth $oauth) {}

    public function index(): Response
    {
        $connections = MailchimpConnection::with('connectedBy:id,name,email')
            ->get()
            ->keyBy('account');

        $accounts = collect(MailchimpConnection::ACCOUNTS)
            ->map(function (string $label, string $key) use ($connections) {
                $connection = $connections->get($key);

                return [
                    'key' => $key,
                    'label' => $label,
                    'connected' => $connection !== null,
                    // The token is $hidden on the model, so nothing here can leak it.
                    'connection' => $connection,
                    'needs_reconnect' => $connection?->needsReconnect() ?? false,
                ];
            })
            ->values();

        return Inertia::render('Settings/MailchimpIntegration', [
            'accounts' => $accounts,
            'oauthConfigured' => $this->oauth->isConfigured(),
            'redirectUri' => $this->oauth->redirectUri(),
        ]);
    }

    /**
     * Send the admin to Mailchimp to authorize. The state parameter is stashed in
     * the session and checked on the way back, so a callback that did not
     * originate from this session is rejected.
     */
    public function connect(Request $request): SymfonyResponse
    {
        $validated = $request->validate([
            'account' => ['required', 'string', 'in:'.implode(',', array_keys(MailchimpConnection::ACCOUNTS))],
        ]);

        if (! $this->oauth->isConfigured()) {
            return back()->with('error', 'Mailchimp OAuth is not configured. Set MAILCHIMP_OAUTH_CLIENT_ID and MAILCHIMP_OAUTH_CLIENT_SECRET.');
        }

        $state = $this->oauth->newState();

        $request->session()->put(MailchimpOAuth::SESSION_KEY, [
            'state' => $state,
            'account' => $validated['account'],
        ]);

        // Inertia::location, not redirect()->away — the request arrives over XHR and
        // an ordinary 302 to another origin would be followed by fetch and blocked
        // by CORS. This returns a 409 that tells Inertia to do a full page visit.
        return Inertia::location($this->oauth->authorizeUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        // Consumed whatever happens, so a state value can never be replayed.
        $pending = $request->session()->pull(MailchimpOAuth::SESSION_KEY);

        if ($request->filled('error')) {
            return redirect()->route('mailchimp.integration.index')
                ->with('error', 'Mailchimp authorization was cancelled or denied.');
        }

        if (! is_array($pending) || ! isset($pending['state'], $pending['account'])) {
            return redirect()->route('mailchimp.integration.index')
                ->with('error', 'That authorization link has expired. Please start the connection again.');
        }

        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ($state === '' || ! hash_equals($pending['state'], $state)) {
            return redirect()->route('mailchimp.integration.index')
                ->with('error', 'The authorization response failed its security check and was discarded.');
        }

        if ($code === '') {
            return redirect()->route('mailchimp.integration.index')
                ->with('error', 'Mailchimp did not return an authorization code.');
        }

        try {
            $token = $this->oauth->exchangeCode($code);
            $meta = $this->oauth->metadata($token);
        } catch (MailchimpOAuthException $e) {
            return redirect()->route('mailchimp.integration.index')
                ->with('error', $e->getMessage());
        }

        $connection = MailchimpConnection::updateOrCreate(
            ['account' => $pending['account']],
            [
                'access_token' => $token,
                'datacenter' => $meta['datacenter'],
                'mailchimp_account_id' => $meta['account_id'],
                'mailchimp_account_name' => $meta['account_name'],
                'status' => MailchimpConnection::STATUS_ACTIVE,
                'connected_by_user_id' => $request->user()->id,
                'connected_at' => now(),
            ]
        );

        MailchimpAuditLog::record(MailchimpAuditLog::CONNECTED, [
            'account' => $connection->account,
            'datacenter' => $connection->datacenter,
            'mailchimp_account_id' => $connection->mailchimp_account_id,
            'mailchimp_account_name' => $connection->mailchimp_account_name,
        ]);

        return redirect()->route('mailchimp.integration.index')
            ->with('success', "Connected to {$connection->mailchimp_account_name} ({$connection->datacenter}).");
    }

    /**
     * Revoke with Mailchimp first, then drop the local record. If Mailchimp
     * refuses the revoke the record is still removed — holding a token we cannot
     * revoke is worse than holding none — but the admin is told plainly so they
     * can finish the job from their Mailchimp account.
     */
    public function disconnect(MailchimpConnection $connection): RedirectResponse
    {
        $revoked = $this->oauth->revoke($connection->access_token);

        $context = [
            'account' => $connection->account,
            'mailchimp_account_id' => $connection->mailchimp_account_id,
            'revoked_with_mailchimp' => $revoked,
        ];

        $connection->delete();

        MailchimpAuditLog::record(
            $revoked ? MailchimpAuditLog::DISCONNECTED : MailchimpAuditLog::REVOKE_FAILED,
            $context
        );

        if (! $revoked) {
            return redirect()->route('mailchimp.integration.index')->with(
                'error',
                'The local connection was removed, but Mailchimp did not confirm the token was revoked. '
                .'Please also remove this app under Account → Extras → Registered Apps in Mailchimp.'
            );
        }

        return redirect()->route('mailchimp.integration.index')
            ->with('success', 'Disconnected and the token was revoked with Mailchimp.');
    }
}
