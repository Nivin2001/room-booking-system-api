<?php
namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundOperationEvidence;
use App\Enums\RefundOperationSource;
use App\Enums\RefundOperationStatus;
use App\Events\BookingCreated;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\RefundOperation;
use App\Models\Space;
use App\Models\User;
use App\Services\AvailabilityService;
use App\Services\PricingService;
use App\Services\RefundOperationService;
use App\Services\stripe\StripeRefundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookingService
{
    public function __construct(
        protected AvailabilityService $availabilityService,
        protected PricingService $pricingService,
        protected StripeRefundService $stripeRefundService,
        protected RefundOperationService $refundOperationService,
        protected RefundOutcomeService $refundOutcomeService,
    ) {}

//    public function createBooking($user, array $data): Booking
// {
//     $booking = DB::transaction(function () use ($user, $data) {

//         // 1. Check availability
//         // Lock existing bookings for this space to avoid race conditions
//         Booking::where('space_id', $data['space_id'])->lockForUpdate()->get();

//         $isAvailable = $this->availabilityService->isAvailable(
//             $data['space_id'],
//             $data['start_time'],
//             $data['end_time']
//         );

//         if (!$isAvailable) {
//             throw new \Exception('Space is not available');
//         }

//         // 2. Calculate price
//         $price = $this->pricingService->calculatePrice(
//             $data['space_id'],
//             $data['start_time'],
//             $data['end_time']
//         );

//         // 3. Create booking
//         return Booking::create([
//             'user_id' => $user->id,
//             'space_id' => $data['space_id'],
//             'start_time' => $data['start_time'],
//             'end_time' => $data['end_time'],
//             'total_amount' => $price,
//             'status' => BookingStatus::PendingPayment,
//             'expires_at' => now()->addMinutes(10),
//         ]);
//     });

//     DB::afterCommit(function () use ($booking) {
//         event(new BookingCreated($booking));
//     });

//     return $booking;
// }
    public function createBooking($user, array $data): Booking
    {
        $booking = DB::transaction(function () use ($user, $data) {
            // The space row exists even when it has no bookings, so it is the
            // stable serialization point for all booking attempts for a space.
            $space = Space::query()
                ->lockForUpdate()
                ->findOrFail($data['space_id']);

            [$start, $end] = $this->availabilityService->validateTimeRange(
                $space,
                $data['start_time'],
                $data['end_time'],
            );

            $isAvailable = $this->availabilityService->isAvailable(
                $data['space_id'],
                $start,
                $end,
            );

            if (! $isAvailable) {
                throw new \DomainException('Space is not available');
            }

            $price = $this->pricingService->calculatePrice(
                $data['space_id'],
                $start,
                $end,
            );

            return Booking::create([
                'user_id' => $user->id,
                'space_id' => $data['space_id'],
                'start_time' => $start,
                'end_time' => $end,
                'total_amount' => $price,
                'status' => BookingStatus::PendingPayment,
                'expires_at' => now()->addMinutes(10),
            ]);
        }, 3);

        DB::afterCommit(function () use ($booking) {
            event(new BookingCreated($booking));
        });

        return $booking;
    }
    public function getSlots($spaceId, $date)
    {
        return app(AvailabilityService::class)->getSlots($spaceId, $date);
    }

    public function getBooking($id)
    {
        return Booking::with('space')->findOrFail($id);
    }

    public function getAllBookings()
    {
        return Booking::with(['space', 'user'])->latest()->get();
    }

    public function list(array $filters = [])
    {
        $query = Booking::with(['space', 'user']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['space_id'])) {
            $query->where('space_id', $filters['space_id']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['date'])) {
            $query->whereDate('start_time', $filters['date']);
        }

        return $query->latest()->get();
    }

    public function listForStaff($staff, array $filters = [])
    {
        $query = Booking::with(['space', 'user'])
            ->whereHas('space', function ($query) use ($staff) {
                $query->where('staff_id', $staff->id);
            });

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['space_id'])) {
            $query->where('space_id', $filters['space_id']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['date'])) {
            $query->whereDate('start_time', $filters['date']);
        }

        return $query->latest()->get();
    }

    public function getUserBookings($userId)
    {
        return Booking::with('space')
            ->where('user_id', $userId)
            ->latest()
            ->get();
    }

    public function updateBooking($user, $id, array $data): Booking
    {
        return DB::transaction(function () use ($user, $id, $data) {
            $booking = Booking::where('id', $id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            $space = Space::query()
                ->lockForUpdate()
                ->findOrFail($booking->space_id);

            if ($booking->status === BookingStatus::Cancelled) {
                throw new \Exception('Cannot update a cancelled booking');
            }

            if ($booking->status === BookingStatus::Confirmed) {
                throw new \DomainException('Cannot update a confirmed booking. Please cancel and rebook or request reschedule');
            }

            if (now()->greaterThan($booking->start_time)) {
                throw new \DomainException('Cannot update past or ongoing booking');
            }

            [$start, $end] = $this->availabilityService->validateTimeRange(
                $space,
                $data['start_time'],
                $data['end_time'],
            );

            $isAvailable = $this->availabilityService->isAvailable(
                $booking->space_id,
                $start,
                $end,
                $booking->id
            );

            if (! $isAvailable) {
                throw new \DomainException('This time slot is already booked');
            }

            $booking->update([
                'start_time' => $start,
                'end_time' => $end,
            ]);

            return $booking->fresh();
        }, 3);
    }


public function cancelBooking(User $user, int|string $id, ?string $requestKey = null): Booking
{
    $snapshot = Booking::with('payment')->where('id', $id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    $paymentId = $snapshot->payment?->id;

    if ($paymentId === null) {
        return DB::transaction(function () use ($user, $id) {
            $booking = Booking::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->findOrFail($id);

            $this->assertCustomerMayCancel($booking);

            if ($booking->status === BookingStatus::Confirmed) {
                throw new \DomainException('A confirmed booking requires a paid payment before cancellation.');
            }

            BookingStatusService::changeStatus($booking, BookingStatus::Cancelled);

            return $booking->fresh();
        }, 3);
    }

    [$booking, $operation, $shouldSubmit, $payment] = DB::transaction(function () use ($user, $id, $paymentId, $requestKey) {
        // Lock order: Payment -> Booking -> RefundOperation, matching the current payment-first webhook order.
        $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);
        $booking = Booking::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->findOrFail($id);

        if ((int) $payment->booking_id !== (int) $booking->id) {
            throw new \DomainException('The booking payment relationship changed; retry the request.');
        }

        if ($booking->status === BookingStatus::PendingStaffApproval) {
            throw new \DomainException('Customer cancellation is not allowed while staff approval is pending.');
        }

        if (in_array($booking->status, [BookingStatus::PendingPayment, BookingStatus::PaymentFailed], true)) {
            if (now()->greaterThan($booking->start_time)) {
                throw new \DomainException('Cannot cancel a past or ongoing booking.');
            }

            if ($payment->status === PaymentStatus::Paid) {
                throw new \DomainException('A paid payment on a non-confirmed booking requires reconciliation before cancellation.');
            }

            BookingStatusService::changeStatus($booking, BookingStatus::Cancelled);

            return [$booking->fresh(), null, false, $payment];
        }

        if ($booking->status === BookingStatus::Cancelled) {
            $operation = blank($requestKey) ? null : $this->refundOperationService->findExistingRequest(
                $payment,
                RefundOperationSource::CustomerCancel,
                $user,
                $requestKey,
            );

            if ($operation === null || $operation->status !== RefundOperationStatus::Succeeded) {
                throw new \DomainException('Booking is already cancelled.');
            }

            return [$booking, $operation, false, $payment];
        }

        if ($booking->status !== BookingStatus::Confirmed) {
            throw new \DomainException('This booking cannot be cancelled in its current state.');
        }

        if (blank($requestKey)) {
            throw new \DomainException('The Idempotency-Key header is required for a paid booking cancellation.');
        }

        $operation = $this->refundOperationService->findExistingRequest(
            $payment,
            RefundOperationSource::CustomerCancel,
            $user,
            $requestKey,
        );

        if ($operation === null) {
            if (now()->greaterThan($booking->start_time)) {
                throw new \DomainException('Cannot cancel a past or ongoing booking.');
            }

            $operation = $this->refundOperationService->reserve(
                $payment,
                RefundOperationSource::CustomerCancel,
                $user,
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

        return [$booking, $operation, $shouldSubmit, $payment];
    }, 3);

    if ($operation === null) {
        return $booking;
    }

    if (! $shouldSubmit) {
        return $this->withRefundOperation($booking, $operation);
    }

    try {
        $refund = $this->stripeRefundService->refund($payment, $operation);
    } catch (\Throwable $exception) {
        Log::warning('Stripe refund response is unknown; operation remains active.', [
            'refund_operation_id' => $operation->id,
            'exception' => class_basename($exception),
        ]);

        $operation = $this->recordRefundOutcome(
            $user,
            $operation,
            RefundOperationStatus::Unknown,
            null,
            class_basename($exception),
        );

        return $this->withRefundOperation($booking->fresh(), $operation);
    }

    $operation = $this->refundOutcomeService->recordStripeRefundObject(
        $operation,
        $refund,
        RefundOperationEvidence::ProviderResponse,
        $user,
    );

    return $this->withRefundOperation($booking->fresh(), $operation);
}

private function assertCustomerMayCancel(Booking $booking): void
{
    if ($booking->status === BookingStatus::PendingStaffApproval) {
        throw new \DomainException('Customer cancellation is not allowed while staff approval is pending.');
    }

    if ($booking->status === BookingStatus::Cancelled) {
        throw new \DomainException('Booking is already cancelled.');
    }

    if (now()->greaterThan($booking->start_time)) {
        throw new \DomainException('Cannot cancel a past or ongoing booking.');
    }
}

private function recordRefundOutcome(
    User $user,
    RefundOperation $operation,
    RefundOperationStatus $status,
    ?string $refundId,
    ?string $lastError,
): RefundOperation {
    return $this->refundOutcomeService->record(
        $operation,
        $status,
        $refundId,
        $lastError,
            RefundOperationEvidence::ProviderResponse,
        $user,
    );
}

private function withRefundOperation(Booking $booking, RefundOperation $operation): Booking
{
    $booking->setAttribute('refund_operation_id', $operation->id);
    $booking->setAttribute('refund_operation_status', $operation->status->value);

    return $booking->fresh()->setAttribute('refund_operation_id', $operation->id)
        ->setAttribute('refund_operation_status', $operation->status->value);
}
}
