<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Form extends Model
{
    use HasFactory;

    protected $fillable = ['event_id'];

    public function fields()
    {
        return $this->hasMany(FormField::class);
    }

    public function event()
    {
        return $this->belongsTo(Events::class, 'event_id');
    }
}
