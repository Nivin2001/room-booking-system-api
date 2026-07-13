<?php

namespace App\Http\Controllers\Api\Customer;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\Stripe\StripeCheckoutService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        protected StripeCheckoutService $stripeService
    ) {}

    public function checkout(Request $request)
    {
        $data = $request->validate([
            'booking_id' => 'required|exists:bookings,id'
        ]);

        $booking = Booking::where('id', $data['booking_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($booking->status !== BookingStatus::PendingPayment) {
            return response()->json([
                'success' => false,
                'message' => 'Booking is not eligible for payment'
            ], 422);
        }

        $session = $this->stripeService->createCheckoutSession($booking);

        return response()->json([
            'success' => true,
            'url' => $session['url'],
            'payment_id' => $session['payment']->id,
        ]);
    }
}
