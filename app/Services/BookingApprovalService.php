<?php
namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Enums\PaymentStatus;
use App\Services\Stripe\StripeRefundService;
use Illuminate\Support\Facades\DB;

class BookingApprovalService
{
    public function __construct(
    protected StripeRefundService $stripeRefundService
) {}
   public function approve($bookingId): Booking
{
    $booking = Booking::findOrFail($bookingId);

    if ($booking->status !== BookingStatus::PendingStaffApproval) {
        throw new \Exception('Booking is not awaiting approval');
    }
    $booking->update([
        'status' => BookingStatus::Confirmed,
        'approved_by' => auth()->id(),
        'approved_at' => now(),
    ]);

    return $booking->fresh();
}

    public function reject($bookingId): Booking
{
    return DB::transaction(function () use ($bookingId) {

        $booking = Booking::with('payment')->findOrFail($bookingId);

        if ($booking->status !== BookingStatus::PendingStaffApproval) {
            throw new \Exception('Booking is not awaiting approval');
        }

        $payment = $booking->payment;

        if ($payment) {
            // Refund will be triggered here later
            // StripeRefundService::refund($payment);
            $payment->update([
                // 'status' => PaymentStatus::Refunded,
                $this->stripeRefundService->refund($payment)
            ]);
        }

        $booking->update([
            'status' => BookingStatus::Rejected,
            'rejected_at' => now(),
        ]);

        return $booking->fresh();
    });
}
}
