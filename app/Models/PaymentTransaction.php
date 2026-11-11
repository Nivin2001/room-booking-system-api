<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    //
      protected $fillable = [
        'payment_id',
        'refund_operation_id',
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

public function refundOperation()
{
    return $this->belongsTo(RefundOperation::class);
}
}
