<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Events extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'image',
        'date',
        'description',
    ];

    public function forms()
    {
        return $this->hasMany(Form::class, 'event_id');
    }

    public function formResponses()
    {
        return $this->hasMany(FormResponse::class, 'event_id');
    }


}
