<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Films extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
    ];

    public function events()
    {
        return $this->hasMany(Events::class);
    }
}
