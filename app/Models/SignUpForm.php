<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SignUpForm extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'questions',
        'table_name',
        'event_banner',
        'heading',
        'event_description',
        'privacy_link',
        'terms_link',
    ];
}
