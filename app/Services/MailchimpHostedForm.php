<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Relays a sign-up to the audience's own hosted Mailchimp form.
 *
 * Mailchimp holds addresses that unsubscribed, bounced or failed compliance review
 * in a compliance state, and refuses to resubscribe them through the API at any
 * privilege level. Its hosted form is the one route back, because a submission
 * there is treated as the contact opting in themselves.
 *
 * That is exactly what makes this legitimate here and nowhere else: it runs while
 * someone is filling in the sign-up form, with the address they just typed, and
 * relays their own act. It must never be pointed at a list of contacts — doing so
 * would assert consent nobody gave, which is the thing the compliance state exists
 * to prevent.
 */
class MailchimpHostedForm
{
    /**
     * Mailchimp only answers post-json in JSON when a callback name is supplied;
     * without one it returns the whole HTML form page.
     */
    private const CALLBACK = 'c';

    /** Submitted and accepted. */
    public const ACCEPTED = 'accepted';

    /** Mailchimp wants the contact to confirm by email before restoring them. */
    public const CONFIRMATION_SENT = 'confirmation_sent';

    /** Already on the list — nothing to do, and not a failure. */
    public const ALREADY_SUBSCRIBED = 'already_subscribed';

    /**
     * The form turned this submission away for now rather than turning the contact
     * down. It is a public web form with abuse protection in front of it, and it
     * starts answering "there was an error, please try again later" after a run of
     * submissions — measured at sixteen inside forty seconds. Nothing about the
     * contact is wrong, so this must never be recorded as a refusal.
     */
    public const THROTTLED = 'throttled';

    public const REJECTED = 'rejected';

    /** No hosted form is configured for this account. */
    public const UNAVAILABLE = 'unavailable';

    /**
     * The same form, addressed to a person rather than posted to.
     *
     * Handed to a compliance-locked contact so they can opt back in themselves, and
     * built from the same configuration the relay posts to — two copies of these
     * parameters would eventually disagree, and the link is the half nobody would
     * notice had gone stale.
     */
    public function subscribeUrl(string $account): ?string
    {
        $form = config("services.mailchimp.hosted_form.$account");

        if (! $form || empty($form['domain'])) {
            return null;
        }

        return $form['domain'].'/subscribe/post?'.http_build_query(array_filter([
            'u' => $form['u'] ?? null,
            'id' => $form['id'] ?? null,
            'f_id' => $form['f_id'] ?? null,
        ], fn ($value) => $value !== null));
    }

    /**
     * @return array{status: string, detail: ?string}
     */
    public function submit(string $account, string $email): array
    {
        $form = config("services.mailchimp.hosted_form.$account");

        if (! $form || empty($form['domain'])) {
            return ['status' => self::UNAVAILABLE, 'detail' => "No hosted form is configured for the {$account} account."];
        }

        $query = array_filter([
            'u' => $form['u'] ?? null,
            'id' => $form['id'] ?? null,
            'f_id' => $form['f_id'] ?? null,
            'EMAIL' => $email,
            'tags' => $form['tag'] ?? null,
            // Mailchimp's bot trap. The hosted form ships it empty and rejects the
            // submission when it comes back filled, so it is sent empty here too.
            'b_'.($form['u'] ?? '').'_'.($form['id'] ?? '') => '',
            self::CALLBACK => 'cb',
        ], fn ($value) => $value !== null);

        try {
            $response = Http::timeout(15)->get($form['domain'].'/subscribe/post-json', $query);
        } catch (\Throwable $e) {
            Log::warning('Hosted Mailchimp form did not answer', [
                'account' => $account,
                'error' => $e->getMessage(),
            ]);

            return ['status' => self::REJECTED, 'detail' => $e->getMessage()];
        }

        return $this->interpret($this->decode($response->body()));
    }

    /**
     * post-json answers as JSONP — cb({...}) — so the wrapper comes off before the
     * body can be read.
     *
     * @return array<string, mixed>
     */
    protected function decode(string $body): array
    {
        if (preg_match('/^[^(]*\((.*)\)\s*;?\s*$/s', trim($body), $matches)) {
            $body = $matches[1];
        }

        return json_decode($body, true) ?: [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: string, detail: ?string}
     */
    protected function interpret(array $payload): array
    {
        // Mailchimp's own words either way, kept verbatim: when an admin asks what
        // happened to a contact, the answer should be the one Mailchimp gave.
        $message = strip_tags((string) ($payload['msg'] ?? ''));

        if (($payload['result'] ?? null) === 'success') {
            // A contact coming back from a compliance state is not restored by the
            // submission itself — Mailchimp emails them to confirm, and only their
            // click puts them back. Saying "resubscribed" here would be a guess.
            return [
                'status' => str_contains(strtolower($message), 'almost finished')
                    || str_contains(strtolower($message), 'confirm')
                        ? self::CONFIRMATION_SENT
                        : self::ACCEPTED,
                'detail' => $message ?: null,
            ];
        }

        $lower = strtolower($message);

        if (str_contains($lower, 'already subscribed')) {
            return ['status' => self::ALREADY_SUBSCRIBED, 'detail' => $message];
        }

        // Said of the submission, not of the contact — and the wording is generic
        // enough that treating it as a refusal marks a perfectly recoverable person
        // as unrecoverable.
        if (str_contains($lower, 'try again later')
            || str_contains($lower, 'too many')
            || str_contains($lower, 'try again in')) {
            return ['status' => self::THROTTLED, 'detail' => $message];
        }

        return ['status' => self::REJECTED, 'detail' => $message ?: 'Mailchimp rejected the submission.'];
    }
}
