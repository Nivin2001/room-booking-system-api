<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    //

        protected $fillable = [
        'user_id',
        'space_id',
        'start_time',
        'end_time',
        'total_amount',
        'currency',
        'status',
        'expires_at',
        'approved_by',
        'approved_at',
        'cancelled_at',
        'rejected_at',
        'reminder_sent'
    ];

       protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'expires_at' => 'datetime',
        'approved_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'rejected_at' => 'datetime',
        'status' => BookingStatus::class,
    ];

    public function user()
    {
        return $this->belongsTo(User::class,'user_id');
    }

    public function space()
    {
        return $this->belongsTo(Space::class);
    }
    public function payment()
{
    return $this->hasOne(Payment::class);
}
public function approver()
{
    return $this->belongsTo(User::class, 'approved_by');
}
}
