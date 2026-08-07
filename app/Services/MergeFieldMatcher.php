<?php

namespace App\Services;

/**
 * First guess at which CSV column feeds which Mailchimp merge tag.
 *
 * Only obvious matches are made. The admin confirms the mapping either way, so a
 * wrong guess costs a correction, but a missed guess costs nothing — when in
 * doubt this leaves the column unmapped.
 */
class MergeFieldMatcher
{
    /**
     * Normalized header text => merge tag. Checked before the merge field's own
     * name and tag, so a column called "first name" reaches FNAME even though
     * Mailchimp calls that field "First Name".
     */
    protected const ALIASES = [
        'email' => 'EMAIL',
        'emailaddress' => 'EMAIL',
        'email address' => 'EMAIL',
        'e mail' => 'EMAIL',
        'mail' => 'EMAIL',
        'firstname' => 'FNAME',
        'first name' => 'FNAME',
        'fname' => 'FNAME',
        'givenname' => 'FNAME',
        'given name' => 'FNAME',
        'lastname' => 'LNAME',
        'last name' => 'LNAME',
        'lname' => 'LNAME',
        'surname' => 'LNAME',
        'familyname' => 'LNAME',
        'family name' => 'LNAME',
        'phone' => 'PHONE',
        'phonenumber' => 'PHONE',
        'phone number' => 'PHONE',
        'mobile' => 'PHONE',
        'birthday' => 'BIRTHDAY',
        'dateofbirth' => 'BIRTHDAY',
        'company' => 'COMPANY',
        'address' => 'ADDRESS',
    ];

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array{tag: string, name: string}>  $mergeFields
     * @return array<string, string> header => merge tag, unmapped headers omitted
     */
    public function match(array $headers, array $mergeFields): array
    {
        $available = collect($mergeFields)->keyBy(fn (array $field) => $field['tag']);

        // A merge tag takes at most one column. Without this, "name" and "first
        // name" in the same file could both claim FNAME and the second would
        // silently overwrite the first.
        $taken = [];
        $map = [];

        foreach ($headers as $header) {
            $tag = $this->tagFor($header, $mergeFields);

            if ($tag === null || isset($taken[$tag]) || ! $available->has($tag)) {
                continue;
            }

            $taken[$tag] = true;
            $map[$header] = $tag;
        }

        return $map;
    }

    /**
     * @param  array<int, array{tag: string, name: string}>  $mergeFields
     */
    protected function tagFor(string $header, array $mergeFields): ?string
    {
        $normalized = $this->normalize($header);

        if ($normalized === '') {
            return null;
        }

        if (isset(self::ALIASES[$normalized])) {
            return self::ALIASES[$normalized];
        }

        // Collapse spacing too, so "first_name" and "first name" both land.
        $collapsed = str_replace(' ', '', $normalized);

        if (isset(self::ALIASES[$collapsed])) {
            return self::ALIASES[$collapsed];
        }

        foreach ($mergeFields as $field) {
            if ($collapsed === str_replace(' ', '', $this->normalize($field['tag']))
                || $collapsed === str_replace(' ', '', $this->normalize($field['name']))) {
                return $field['tag'];
            }
        }

        return null;
    }

    /**
     * Lowercase, punctuation to spaces, runs of whitespace collapsed. Turns
     * "E-Mail_Address" and "email address" into the same thing.
     */
    protected function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

        return trim(preg_replace('/\s+/', ' ', $value));
    }
}
