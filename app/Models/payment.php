<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    //
        protected $fillable = [
        'booking_id',
        'provider',
        'provider_reference',
        'payment_intent_id',
        'status',
        'amount',
        'currency',
        'paid_at',
        'failed_at',
    ];

    protected $casts = [
        'status' => PaymentStatus::class,
        'paid_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function booking()
{
    return $this->belongsTo(Booking::class);
}

public function transactions()
{
    return $this->hasMany(PaymentTransaction::class);
}
}

