<?php

namespace App\Services\Stripe;

use App\Models\Booking;
use App\Models\Payment;
use Stripe\Stripe;
use Stripe\Checkout\Session;

class StripeCheckoutService
{
    public function createCheckoutSession(Booking $booking)
    {
        // Initialize Stripe
        Stripe::setApiKey(config('services.stripe.secret'));

        // Create internal payment record
        $payment = Payment::create([
            'booking_id' => $booking->id,
            'amount' => $booking->total_amount,
            'currency' => 'usd',
            'status' => 'pending',
        ]);

        // Create Stripe Checkout Session
        $session = Session::create([
            'payment_method_types' => ['card'],

            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',

                    'product_data' => [
                        'name' => 'Room Booking #' . $booking->id,
                    ],

                    'unit_amount' => (int) ($booking->total_amount * 100),
                ],

                'quantity' => 1,
            ]],

            'mode' => 'payment',

            'success_url' => config('app.url') . '/payment/success',
            'cancel_url' => config('app.url') . '/payment/cancel',

            'metadata' => [
                'booking_id' => $booking->id,
                'payment_id' => $payment->id,
            ],
        ]);

        // Save Stripe session id
        $payment->update([
            'provider_reference' => $session->id,
        ]);

        return [
            'url' => $session->url,
            'payment' => $payment,
        ];
    }
}
