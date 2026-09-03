<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A short redirect the app serves at /s/{code}.
 *
 * Exists because Mailchimp only shortens links for campaigns built in its own
 * editor; content set through the API goes out at full length, and a ticket
 * URL alone is longer than an SMS segment. Bitly's free tier is five links a
 * month with an ad interstitial, so the app does it itself.
 */
class ShortLink extends Model
{
    protected $fillable = ['code', 'url', 'url_hash', 'clicks', 'last_clicked_at'];

    protected $casts = ['last_clicked_at' => 'datetime'];
}
