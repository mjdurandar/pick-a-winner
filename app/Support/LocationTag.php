<?php

namespace App\Support;

/**
 * The location labels used in Mailchimp tags.
 *
 * Two labels come off one location name, and they differ on purpose:
 *
 *   SHOW   - where the screening is. USA and Canada keep the state, because
 *            "PORTLAND" alone is ambiguous there; everywhere else does not.
 *   SOURCE - where a contact came from. Never carries a state, in any country.
 *
 * This lived in five places and drifted. The sign-up sync kept the state on
 * every Australian SOURCE tag ("SAWTELL NSW"), while the import paths stripped
 * the last word of any two-word town ("BYRON BAY" -> "BYRON"). Both are fixed
 * here, and the callers now share this rather than each carrying a copy.
 *
 * Mirrored by locationTagForSource()/locationTagForShow() in LocationPage.vue
 * and LocationAttendees.vue, which preview these tags in the browser.
 */
class LocationTag
{
    /**
     * State and province abbreviations that may trail a location name.
     *
     * An allowlist, not a pattern. "/\s+[A-Z]{2,3}$/" matches the last word of
     * any two-word town, which is how BYRON BAY became BYRON and HALLS GAP
     * became HALLS. Only a real abbreviation should come off.
     */
    public const STATE_ABBREVIATIONS = [
        // Australia
        'ACT', 'NSW', 'NT', 'QLD', 'SA', 'TAS', 'VIC', 'WA',
        // Canada
        'AB', 'BC', 'MB', 'NB', 'NL', 'NS', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT',
        // United States
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI',
        'ID', 'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN',
        'MS', 'MO', 'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH',
        'OK', 'OR', 'PA', 'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WV',
        'WI', 'WY',
    ];

    /** Event countries whose SHOW tag keeps the state. */
    private const STATE_COUNTRIES = ['USA', 'CANADA', 'USA & CANADA', 'USA AND CANADA'];

    /** Everything before the first " - ": "Sawtell NSW - Regional Cinema" is in Sawtell. */
    public static function label(?string $name): string
    {
        return trim(explode(' - ', (string) $name)[0]);
    }

    /** True when this country's SHOW tags carry a state. */
    public static function keepsState(?string $country): bool
    {
        return in_array(mb_strtoupper(trim((string) $country)), self::STATE_COUNTRIES, true);
    }

    /**
     * The SOURCE-tag label: the place, never the state.
     */
    public static function source(?string $name, ?string $state = null): string
    {
        $label = self::label($name);

        return $label === '' ? 'LOC' : mb_strtoupper(self::stripState($label, $state));
    }

    /**
     * The SHOW-tag label: the place, plus the state where that country uses one.
     */
    public static function show(?string $name, ?string $state, ?string $country): string
    {
        $label = self::label($name);
        if ($label === '') {
            return 'LOC';
        }

        if (! self::keepsState($country)) {
            return mb_strtoupper(self::stripState($label, $state));
        }

        // Prefer the recorded state, falling back to one written into the name.
        $code = trim((string) $state) !== '' ? trim((string) $state) : self::stateInName($label);

        if ($code === '') {
            return mb_strtoupper($label);
        }

        // Stripped first, then re-appended, so a name that already carries the
        // state does not end up with it twice or in two different formats.
        return mb_strtoupper(self::stripState($label, $code).', '.$code);
    }

    /**
     * Drop a trailing state from a label, if there is one.
     *
     * The recorded state wins when set, matched on a word boundary: without
     * one, state "SA" turns "MOUNT ISA" into "MOUNT I". Otherwise the last word
     * comes off only when it is a known abbreviation.
     */
    public static function stripState(string $label, ?string $state = null): string
    {
        $state = trim((string) $state);

        if ($state !== '') {
            $out = preg_replace('/(,\s*|\s+)'.preg_quote($state, '/').'$/i', '', $label);

            if ($out !== null && trim($out) !== '') {
                return trim($out);
            }
        }

        if (preg_match('/^(.*?)(,\s*|\s+)([A-Za-z]{2,3})$/', $label, $m)
            && in_array(mb_strtoupper($m[3]), self::STATE_ABBREVIATIONS, true)
            && trim($m[1]) !== '') {
            return trim($m[1]);
        }

        return $label;
    }

    /** A state abbreviation written into the name itself, or '' when there is none. */
    public static function stateInName(string $label): string
    {
        if (preg_match('/^(.*?)(,\s*|\s+)([A-Za-z]{2,3})$/', $label, $m)
            && in_array(mb_strtoupper($m[3]), self::STATE_ABBREVIATIONS, true)
            && trim($m[1]) !== '') {
            return mb_strtoupper($m[3]);
        }

        return '';
    }
}
