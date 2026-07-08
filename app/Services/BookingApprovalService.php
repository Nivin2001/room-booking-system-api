<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Enums\PaymentStatus;
use App\Events\BookingApproved;
use App\Events\BookingRejected;
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

        BookingStatusService::changeStatus(
            $booking,
            BookingStatus::Confirmed,
            [
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]
        );
        event(new BookingApproved($booking));

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
                if (!$payment->payment_intent_id) {
                    throw new \Exception('Payment intent is missing, refund cannot be processed');
                }

                $refund = $this->stripeRefundService->refund($payment);

                $payment->update([
                    'status' => PaymentStatus::Refunded,
                ]);

                //     $payment->transactions()->create([
                //         'type' => 'refund',
                //         'amount' => $payment->amount,
                //         'currency' => $payment->currency,
                //         'meta' => [
                //             'stripe_refund_id' => $refund->id,
                //             'status' => $refund->status,
                //         ],
                //     ]);
            }

            // $booking->update([
            //     'status' => BookingStatus::Rejected,
            //     'rejected_at' => now(),
            // ]);
            BookingStatusService::changeStatus(
                $booking,
                BookingStatus::Rejected,
                [
                    'rejected_at' => now(),
                ]
            );
            event(new BookingRejected($booking));

            return $booking->fresh();
        });
    }
}
