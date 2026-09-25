<?php

namespace App\Mail\Concerns;

/**
 * Resolves an alert's mailboxes from the first config key that names any.
 *
 * Every sync alert wants the same shape: its own recipients if someone set
 * them, otherwise the general approval list, otherwise the master sheet owner —
 * who is the only account the review screens let in, so an alert that reaches
 * nobody else at least reaches someone who can act on it.
 */
trait ResolvesRecipients
{
    /**
     * The addresses from the first key that yields any.
     *
     * @param  list<string>  $configKeys  most specific first
     * @return list<string>
     */
    protected static function firstConfigured(array $configKeys): array
    {
        foreach ($configKeys as $key) {
            $addresses = self::parseAddresses((string) (config($key) ?? ''));

            if ($addresses !== []) {
                return $addresses;
            }
        }

        return [];
    }

    /**
     * This mail's own CC list — on top of the standing MAIL_ALWAYS_CC, which
     * the send listener adds to everything.
     *
     * @return list<string>
     */
    protected static function ccFrom(string $configKey): array
    {
        return self::parseAddresses((string) (config($configKey) ?? ''));
    }

    /**
     * Comma-separated addresses, ignoring anything that is not one. A typo in an
     * env var should cost that address, not the whole alert.
     *
     * @return list<string>
     */
    protected static function parseAddresses(string $configured): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $configured)),
            fn (string $address) => filter_var($address, FILTER_VALIDATE_EMAIL) !== false
        ));
    }
}
