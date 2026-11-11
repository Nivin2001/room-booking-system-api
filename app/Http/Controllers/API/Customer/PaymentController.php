<?php

namespace App\Http\Controllers\API\Customer;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\stripe\StripeCheckoutService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        protected StripeCheckoutService $stripeService
    ) {}

public function checkout(Request $request)
{
    $idempotencyKey = $request->header('Idempotency-Key');
    if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 16 || strlen($idempotencyKey) > 255) {
        return response()->json([
            'success' => false,
            'message' => 'A valid Idempotency-Key header is required.',
        ], 422);
    }

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

    try {
        $session = $this->stripeService->createCheckoutSession($booking, $idempotencyKey);
    } catch (\DomainException $exception) {
        return response()->json([
            'success' => false,
            'message' => $exception->getMessage(),
        ], 409);
    }

    if (! is_string($session['url'] ?? null)) {
        return response()->json([
            'success' => true,
            'status' => $session['status'] ?? 'unknown',
            'message' => 'Checkout is not ready for another redirect; payment state is pending confirmation.',
            'payment_id' => $session['payment']->id,
        ], 202);
    }

    return response()->json([
        'success' => true,
        'url' => $session['url'],
        'payment_id' => $session['payment']->id,
    ]);
}
}
