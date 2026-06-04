<?php

namespace App\Services\Stripe;

use App\Models\Payment;
use Stripe\Stripe;
use Stripe\Refund;

class StripeRefundService
{
    public function refund(Payment $payment)
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        return Refund::create([
            'payment_intent' => $payment->payment_intent_id,
        ]);
    }
}
