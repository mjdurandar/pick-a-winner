<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormResponse extends Model
{
    use HasFactory;

    protected $fillable = ['event_id', 'form_id', 'responses'];

    // protected $casts = [
    //     'responses' => 'array', // Automatically cast the JSON to an array
    // ];

    public function event()
    {
        return $this->belongsTo(Events::class);
    }

    public function form()
    {
        return $this->belongsTo(Form::class);
    }

    public function field()
    {
        return $this->belongsTo(FormField::class);
    }
    
}
