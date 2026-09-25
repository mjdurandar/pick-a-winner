<?php

namespace App\Listeners;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Copies every outgoing email to the addresses in mail.always_cc.
 *
 * Done at the message level rather than on each Mailable so nothing can be
 * added later that quietly escapes it — a new alert, a queued notification, a
 * one-off Mail::raw from a command all pass through here.
 */
class AddAlwaysCcRecipient
{
    /**
     * Mail whose body is a credential, and so is never copied to anyone.
     *
     * A password reset link is enough on its own to take over the account it was
     * sent to. Copying one to a standing address means every reset any user asks
     * for, including an attacker asking on someone else's behalf, lands in a
     * second mailbox as a usable key. The CC is a convenience; this is not a
     * trade worth making for it.
     */
    protected const NEVER_CC = [
        ResetPassword::class,
        VerifyEmail::class,
    ];

    public function handle(MessageSending $event): void
    {
        $cc = self::addresses();

        if ($cc === [] || $this->carriesCredential($event)) {
            return;
        }

        $message = $event->message;

        // Whoever is already on the message in any capacity. Adding someone twice
        // sends them two copies, and a CC of the person in To is just noise.
        $existing = array_map(
            fn (Address $a) => strtolower($a->getAddress()),
            array_merge($message->getTo(), $message->getCc(), $message->getBcc())
        );

        foreach ($cc as $address) {
            if (! in_array(strtolower($address), $existing, true)) {
                $message->addCc($address);
            }
        }
    }

    /**
     * The configured addresses, ignoring anything that is not an email.
     *
     * @return list<string>
     */
    public static function addresses(): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', (string) (config('mail.always_cc') ?? ''))),
            fn (string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false
        ));
    }

    /**
     * Laravel's mail channel stamps the notification class onto the view data,
     * which is the only reliable way to tell a reset link from an ordinary
     * email once it has been rendered down to a message.
     */
    protected function carriesCredential(MessageSending $event): bool
    {
        $notification = $event->data['__laravel_notification'] ?? null;

        foreach (self::NEVER_CC as $class) {
            if ($notification !== null && is_a($notification, $class, true)) {
                return true;
            }
        }

        return false;
    }
}
