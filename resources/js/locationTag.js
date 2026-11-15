// The location labels used in Mailchimp tags.
//
// Two labels come off one location name, and they differ on purpose:
//
//   SHOW   - where the screening is. USA and Canada keep the state, because
//            "PORTLAND" alone is ambiguous there; everywhere else does not.
//   SOURCE - where a contact came from. Never carries a state, in any country.
//
// This mirrors app/Support/LocationTag.php exactly. The previews on these
// screens have to agree with what the server writes, so keep the two in step:
// the logic lived in five places before and drifted apart.

// An allowlist, not a pattern. /\s+[A-Z]{2,3}$/ matches the last word of any
// two-word town, which is how BYRON BAY became BYRON and HALLS GAP became HALLS.
const STATE_ABBREVIATIONS = new Set([
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
]);

const STATE_COUNTRIES = ['USA', 'CANADA', 'USA & CANADA', 'USA AND CANADA'];

const escape = (s) => String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

/** Everything before the first " - ": "Sawtell NSW - Regional Cinema" is in Sawtell. */
export const locationLabel = (name) => String(name || '').split(' - ')[0].trim();

/** True when this country's SHOW tags carry a state. */
export const countryKeepsState = (country) =>
    STATE_COUNTRIES.includes(String(country || '').trim().toUpperCase());

/** A state abbreviation written into the name itself, or '' when there is none. */
export function stateInName(label) {
    const m = String(label).match(/^(.*?)(,\s*|\s+)([A-Za-z]{2,3})$/);

    return m && STATE_ABBREVIATIONS.has(m[3].toUpperCase()) && m[1].trim() !== ''
        ? m[3].toUpperCase()
        : '';
}

/**
 * Drop a trailing state from a label, if there is one.
 *
 * The recorded state wins when set, matched on a word boundary: without one,
 * state "SA" turns "MOUNT ISA" into "MOUNT I". Otherwise the last word comes
 * off only when it is a known abbreviation.
 */
export function stripState(label, state) {
    const trimmed = String(state || '').trim();

    if (trimmed) {
        const out = String(label).replace(new RegExp(`(,\\s*|\\s+)${escape(trimmed)}$`, 'i'), '').trim();
        if (out) return out;
    }

    const m = String(label).match(/^(.*?)(,\s*|\s+)([A-Za-z]{2,3})$/);
    if (m && STATE_ABBREVIATIONS.has(m[3].toUpperCase()) && m[1].trim() !== '') {
        return m[1].trim();
    }

    return label;
}

/** The SOURCE-tag label: the place, never the state. */
export function locationTagForSource(name, state) {
    const label = locationLabel(name);

    return label ? stripState(label, state).toUpperCase() : 'LOC';
}

/** The SHOW-tag label: the place, plus the state where that country uses one. */
export function locationTagForShow(name, state, country) {
    const label = locationLabel(name);
    if (!label) return 'LOC';

    if (!countryKeepsState(country)) {
        return stripState(label, state).toUpperCase();
    }

    // Prefer the recorded state, falling back to one written into the name.
    const code = String(state || '').trim() || stateInName(label);
    if (!code) return label.toUpperCase();

    // Stripped first, then re-appended, so a name that already carries the
    // state does not end up with it twice or in two different formats.
    return `${stripState(label, code)}, ${code}`.toUpperCase();
}
