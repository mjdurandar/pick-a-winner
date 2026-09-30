<?php

namespace App\Support;

use App\Mail\WeeklyDigest;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

/**
 * Who the weekly digest goes to, and who is copied on it.
 *
 * The default comes from config (WEEKLY_DIGEST_EMAIL / WEEKLY_DIGEST_CC in
 * .env, with the digest's usual fallbacks); an admin can override it from the
 * weekly report screen, and that override lives in the settings table.
 * Resetting deletes the override. MAIL_ALWAYS_CC is not part of this — the
 * send listener adds it to every mail regardless.
 */
class WeeklyDigestRecipients
{
    public const SETTING_KEY = 'weekly_digest.recipients';

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     */
    public function __construct(
        public readonly array $to,
        public readonly array $cc,
    ) {}

    /** Who the digest goes to right now: the override, or the default. */
    public static function current(): self
    {
        $default = self::default();

        try {
            $saved = Setting::get(self::SETTING_KEY);
        } catch (\Throwable $e) {
            // A digest to the default mailboxes beats no digest.
            Log::warning('Could not read the weekly digest recipients; using the default', ['error' => $e->getMessage()]);

            return $default;
        }

        if (! is_array($saved)) {
            return $default;
        }

        $to = self::clean($saved['to'] ?? null);

        return new self(
            // An override with nobody in To would silence the digest entirely.
            $to !== [] ? $to : $default->to,
            // An empty CC is a real choice, though: "copy nobody".
            is_array($saved['cc'] ?? null) ? self::clean($saved['cc']) : $default->cc,
        );
    }

    public static function default(): self
    {
        return new self(WeeklyDigest::defaultRecipients(), WeeklyDigest::defaultCcRecipients());
    }

    public static function isOverridden(): bool
    {
        return is_array(Setting::get(self::SETTING_KEY));
    }

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     */
    public static function save(array $to, array $cc): self
    {
        $recipients = new self(self::clean($to), self::clean($cc));

        Setting::set(self::SETTING_KEY, $recipients->toArray());

        return $recipients;
    }

    public static function reset(): self
    {
        Setting::forget(self::SETTING_KEY);

        return self::default();
    }

    /** Same mailboxes, whatever the order or capitalisation. */
    public function matches(self $other): bool
    {
        return self::comparable($this->to) === self::comparable($other->to)
            && self::comparable($this->cc) === self::comparable($other->cc);
    }

    /** @return array{to: list<string>, cc: list<string>} */
    public function toArray(): array
    {
        return ['to' => $this->to, 'cc' => $this->cc];
    }

    /** @return list<string> */
    protected static function clean(mixed $addresses): array
    {
        if (! is_array($addresses)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($address) => trim((string) $address), $addresses),
            fn (string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false
        )));
    }

    /**
     * @param  list<string>  $addresses
     * @return list<string>
     */
    protected static function comparable(array $addresses): array
    {
        $addresses = array_map('strtolower', $addresses);
        sort($addresses);

        return $addresses;
    }
}
