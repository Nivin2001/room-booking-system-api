<?php

namespace App\Models;

use App\Enums\CheckoutAttemptStatus;
use Illuminate\Database\Eloquent\Model;

class CheckoutAttempt extends Model
{
    protected $fillable = [
        'uuid',
        'payment_id',
        'request_key_hash',
        'request_key_hashes',
        'request_fingerprint',
        'stripe_idempotency_key',
        'stripe_session_id',
        'stripe_payment_intent_id',
        'active_key',
        'status',
        'stripe_session_status',
        'stripe_payment_status',
        'amount',
        'currency',
        'expires_at',
        'last_checked_at',
        'last_error_code',
        'is_legacy',
    ];

    protected $casts = [
        'status' => CheckoutAttemptStatus::class,
        'request_key_hashes' => 'array',
        'amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'last_checked_at' => 'datetime',
        'is_legacy' => 'boolean',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
