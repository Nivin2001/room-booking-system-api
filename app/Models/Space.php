<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Space extends Model
{
    //
    protected $fillable = [
        'title',
        'description',
        'location',
        'price_per_hour',
        'capacity',
        'status',
        'created_by',
    ];
  public function categories()
    {
        return $this->belongsToMany(Category::class, 'category_space');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
