<?php

namespace App\Models;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

use Illuminate\Database\Eloquent\Model;

class Space extends Model implements HasMedia
{
     use InteractsWithMedia;
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
      public function registerMediaCollections(): void
    {
        $this->addMediaCollection('images')
             ->useDisk('public');
    }
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
