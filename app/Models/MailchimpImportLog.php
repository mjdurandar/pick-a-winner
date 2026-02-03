<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailchimpImportLog extends Model
{
    protected $fillable = [
        'location_id',
        'imported_by',
        'total_data',
        'new_contacts',
        'updated_data',
        'data_with_error',
        'errors',
        'tags',
        'source',
        'mailchimp_account',
        'list_id',
        'list_name',
        'has_import_file',
    ];

    protected $casts = [
        'tags' => 'array',
        'errors' => 'array',
        'has_import_file' => 'boolean',
    ];

    /**
     * Canonical order for MC log columns: Tag 1 = Year, Tag 2 = FILM TOUR, Tag 3 = INT,
     * Tag 4 = COUNTRY, Tag 5 = SHOW, Tag 6 = SOURCE, then any others.
     */
    public static function orderTagsForDisplay(array $tags): array
    {
        if (empty($tags)) {
            return [];
        }
        $order = [
            'year' => [],      // 4-digit year
            'film_tour' => [], // FILM TOUR - *
            'int' => [],       // INT - *
            'country' => [],   // COUNTRY - *
            'show' => [],      // SHOW - *
            'source' => [],    // SOURCE - *
            'other' => [],
        ];
        foreach ($tags as $tag) {
            $t = is_string($tag) ? trim($tag) : (string) $tag;
            if ($t === '') {
                continue;
            }
            if (preg_match('/^\d{4}$/', $t)) {
                $order['year'][] = $t;
            } elseif (stripos($t, 'FILM TOUR -') === 0) {
                $order['film_tour'][] = $t;
            } elseif (stripos($t, 'INT -') === 0) {
                $order['int'][] = $t;
            } elseif (stripos($t, 'COUNTRY -') === 0) {
                $order['country'][] = $t;
            } elseif (stripos($t, 'SHOW -') === 0) {
                $order['show'][] = $t;
            } elseif (stripos($t, 'SOURCE -') === 0) {
                $order['source'][] = $t;
            } else {
                $order['other'][] = $t;
            }
        }
        return array_merge(
            $order['year'],
            $order['film_tour'],
            $order['int'],
            $order['country'],
            $order['show'],
            $order['source'],
            $order['other']
        );
    }

    protected function setTagsAttribute($value): void
    {
        $arr = is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : []);
        if (!is_array($arr)) {
            $arr = [];
        }
        // Ensure flat 0-indexed array so no tags are dropped (e.g. from associative keys)
        $arr = array_values(array_filter(array_map(function ($t) {
            return is_string($t) ? trim($t) : (string) $t;
        }, $arr), fn ($t) => $t !== ''));
        $ordered = self::orderTagsForDisplay($arr);
        $this->attributes['tags'] = json_encode($ordered);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
