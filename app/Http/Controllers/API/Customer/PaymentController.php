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
        $request->validate([
            'booking_id' => 'required|exists:bookings,id'
        ]);
        $booking = Booking::findOrFail($request->booking_id);
    if ($booking->status !== BookingStatus::PendingPayment) {// enum vs enum
    // dd($booking->status, BookingStatus::PendingPayment->value);
            return response()->json([
                'success' => false,
                'message' => 'Booking is not eligible for payment'
            ], 422);
        }

     $session = $this->stripeService->createCheckoutSession(
    $booking,
    $booking->total_amount
);

return response()->json([
    'success' => true,
    'url' => $session['url']
]);
    }
}
