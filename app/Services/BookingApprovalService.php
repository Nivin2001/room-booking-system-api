<?php

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Enums\PaymentStatus;
use App\Enums\RefundOperationEvidence;
use App\Enums\RefundOperationSource;
use App\Enums\RefundOperationStatus;
use App\Events\BookingApproved;
use App\Events\BookingRejected;
use App\Models\Payment;
use App\Models\RefundOperation;
use App\Models\User;
use App\Services\RefundOperationService;
use App\Services\RefundOutcomeService;
use App\Services\stripe\StripeRefundService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BookingApprovalService
{
    public function __construct(
        protected StripeRefundService $stripeRefundService,
        protected RefundOperationService $refundOperationService,
        protected RefundOutcomeService $refundOutcomeService,
    ) {}

   public function approve($bookingId): Booking
{
    $lock = Cache::lock("booking:{$bookingId}:state", 10);

    if (! $lock->get()) {
        throw new \Exception(
            'This booking is currently being processed. Please try again.'
        );
    }

    try {
        $booking = DB::transaction(function () use ($bookingId) {

            $booking = Booking::with('space')
                ->lockForUpdate()
                ->findOrFail($bookingId);

            if ($booking->status !== BookingStatus::PendingStaffApproval) {
                throw new \Exception(
                    'Booking is not awaiting staff approval.'
                );
            }

            BookingStatusService::changeStatus(
                $booking,
                BookingStatus::Confirmed,
                [
                    'approved_by' => Auth::id(),
                    'approved_at' => now(),
                ]
            );

            return $booking->fresh();
        });

        DB::afterCommit(function () use ($booking) {
            event(new BookingApproved($booking));
        });

        return $booking;

    } finally {
        $lock->release();
    }
}
public function reject($bookingId, ?string $requestKey = null, ?string $reason = null): Booking
{
    $staff = Auth::user();
    if (! $staff instanceof User) {
        throw new \Illuminate\Auth\AuthenticationException('Authentication is required to reject a booking.');
    }

    if (blank($requestKey)) {
        throw new \DomainException('The Idempotency-Key header is required for a staff rejection.');
    }

    $lock = Cache::lock("booking:{$bookingId}:state", 10);

    if (! $lock->get()) {
        throw new \Exception(
            'This booking is currently being processed. Please try again.'
        );
    }

    try {
        $snapshot = Booking::with(['space', 'payment'])->findOrFail($bookingId);

        if (! $staff->can('reject', $snapshot)) {
            throw new \Exception('Not authorized to reject this booking');
        }

        $paymentId = $snapshot->payment?->id;
        if ($paymentId === null) {
            throw new \Exception('No payment found for this booking');
        }

        if (! $snapshot->payment?->payment_intent_id) {
            throw new \Exception(
                'Payment intent is missing, refund cannot be processed'
            );
        }

        [$booking, $payment, $operation, $shouldSubmit] = DB::transaction(function () use ($bookingId, $paymentId, $staff, $requestKey) {
            // Match customer cancellation's lock order so both paths serialize on one payment.
            $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);
            $booking = Booking::with('space')->lockForUpdate()->findOrFail($bookingId);

            if ((int) $payment->booking_id !== (int) $booking->id) {
                throw new \DomainException('The booking payment relationship changed; retry the request.');
            }

            $operation = $this->refundOperationService->findExistingRequest(
                $payment,
                RefundOperationSource::StaffReject,
                $staff,
                $requestKey,
            );

            if ($booking->status === BookingStatus::Rejected) {
                if ($operation?->status === RefundOperationStatus::Succeeded) {
                    return [$booking, $payment, $operation, false];
                }

                throw new \DomainException('Booking has already been rejected.');
            }

            if ($booking->status !== BookingStatus::PendingStaffApproval) {
                throw new \DomainException('Booking is not awaiting staff approval.');
            }

            // The webhook only moves a booking to staff approval after marking its payment paid.
            if ($payment->status !== PaymentStatus::Paid) {
                throw new \DomainException('A booking awaiting staff approval must have a paid payment before rejection.');
            }

            if ($operation === null) {
                $operation = $this->refundOperationService->reserve(
                    $payment,
                    RefundOperationSource::StaffReject,
                    $staff,
                    $requestKey,
                );
            }

            $shouldSubmit = $operation->status === RefundOperationStatus::Requested;
            if ($shouldSubmit) {
                $operation = $this->refundOperationService->transition(
                    $operation,
                    RefundOperationStatus::Processing,
                );
            }

            return [$booking, $payment, $operation, $shouldSubmit];
        }, 3);

        if (! $shouldSubmit) {
            return $this->withRefundOperation($booking, $operation);
        }

        try {
            $refund = $this->stripeRefundService->refund($payment, $operation);
        } catch (\Throwable $exception) {
            $operation = $this->refundOutcomeService->record(
                $operation,
                RefundOperationStatus::Unknown,
                null,
                class_basename($exception),
                RefundOperationEvidence::ProviderResponse,
            );

            return $this->withRefundOperation($booking->fresh(['space', 'payment']), $operation);
        }

        $operation = $this->refundOutcomeService->recordStripeRefundObject(
            $operation,
            $refund,
            RefundOperationEvidence::ProviderResponse,
            rejectionReason: $reason,
        );

        $booking = $booking->fresh(['space', 'payment']);
        if ($operation->status === RefundOperationStatus::Succeeded) {
            event(new BookingRejected($booking, blank($reason) ? null : trim($reason)));
        }

        return $this->withRefundOperation($booking, $operation);

    } finally {
        $lock->release();
    }
}

private function withRefundOperation(Booking $booking, RefundOperation $operation): Booking
{
    return $booking->setAttribute('refund_operation_id', $operation->id)
        ->setAttribute('refund_operation_status', $operation->status->value);
}
}
