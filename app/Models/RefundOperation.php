<?php

namespace App\Models;

use App\Enums\RefundOperationSource;
use App\Enums\RefundOperationStatus;
use Illuminate\Database\Eloquent\Model;

class RefundOperation extends Model
{
    protected $fillable = [
        'payment_id',
        'source',
        'requested_by',
        'status',
        'amount_minor',
        'currency',
        'idempotency_key',
        'stripe_refund_id',
        'active_key',
        'attempt_count',
        'last_error',
        'requested_at',
        'last_checked_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'source' => RefundOperationSource::class,
            'status' => RefundOperationStatus::class,
            'amount_minor' => 'integer',
            'attempt_count' => 'integer',
            'requested_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function transaction()
    {
        return $this->hasOne(PaymentTransaction::class);
    }
}
