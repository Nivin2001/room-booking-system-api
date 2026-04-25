<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    //
     protected $fillable = [
        'name',
        'description'
    ];

    public function spaces()
    {
        return $this->belongsToMany(Space::class, 'category_space');
    }
}
