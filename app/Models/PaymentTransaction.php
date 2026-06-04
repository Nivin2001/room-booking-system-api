<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    //
      protected $fillable = [
        'payment_id',
        'type',
        'amount',
        'currency',
        'stripe_event_id',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];
    public function payment()
{
    return $this->belongsTo(Payment::class);
}
}
