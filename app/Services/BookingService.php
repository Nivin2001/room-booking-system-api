<?php
namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Events\BookingCreated;
use App\Models\Booking;
use App\Models\PaymentTransaction;
use App\Services\AvailabilityService;
use App\Services\PricingService;
use App\Services\Stripe\StripeRefundService;
use Illuminate\Support\Facades\DB;

class BookingService
{
    public function __construct(
        protected AvailabilityService $availabilityService,
        protected PricingService $pricingService,
        protected StripeRefundService $stripeRefundService
    ) {}

   public function createBooking($user, array $data): Booking
{
    $booking = DB::transaction(function () use ($user, $data) {

        // 1. Check availability
        // Lock existing bookings for this space to avoid race conditions
        Booking::where('space_id', $data['space_id'])->lockForUpdate()->get();

        $isAvailable = $this->availabilityService->isAvailable(
            $data['space_id'],
            $data['start_time'],
            $data['end_time']
        );

        if (!$isAvailable) {
            throw new \Exception('Space is not available');
        }

        // 2. Calculate price
        $price = $this->pricingService->calculatePrice(
            $data['space_id'],
            $data['start_time'],
            $data['end_time']
        );

        // 3. Create booking
        return Booking::create([
            'user_id' => $user->id,
            'space_id' => $data['space_id'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'total_amount' => $price,
            'status' => BookingStatus::PendingPayment,
            'expires_at' => now()->addMinutes(10),
        ]);
    });

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
        return Booking::with(['space', 'user'])->findOrFail($id);
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

    public function getUserBookings($userId)
    {
        return Booking::with('space')
            ->where('user_id', $userId)
            ->latest()
            ->get();
    }

    public function updateBooking($user, $id, array $data): Booking
{
    $booking = Booking::where('id', $id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    if ($booking->status === BookingStatus::Cancelled) {
        throw new \Exception('Cannot update a cancelled booking');
    }

    if ($booking->status === BookingStatus::Confirmed) {
        throw new \Exception('Cannot update a confirmed booking. Please cancel and rebook or request reschedule');
    }

    if (now()->greaterThan($booking->start_time)) {
        throw new \Exception('Cannot update past or ongoing booking');
    }

    $isAvailable = $this->availabilityService->isAvailable(
        $booking->space_id,
        $data['start_time'],
        $data['end_time'],
        $booking->id
    );

    if (!$isAvailable) {
        throw new \Exception('This time slot is already booked');
    }

    $booking->update([
        'start_time' => $data['start_time'],
        'end_time'   => $data['end_time'],
    ]);

    return $booking->fresh();
}


public function cancelBooking($user, $id): Booking
{
    $booking = Booking::where('id', $id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    if ($booking->status === BookingStatus::Cancelled) {
        throw new \Exception('Already cancelled');
    }

    if (now()->greaterThan($booking->start_time)) {
        throw new \Exception('Cannot cancel past or ongoing booking');
    }
    // already paid but not started yet, allow refund process
    if ($booking->status === BookingStatus::Confirmed) {
        $payment = $booking->payment;

        if (! $payment) {
            throw new \Exception('No payment record found for this confirmed booking');
        }

        if (! $payment->payment_intent_id) {
            throw new \Exception('Payment intent is missing, refund cannot be processed');
        }

        // Perform refund via Stripe
        $refund = $this->stripeRefundService->refund($payment);

        // update payment record
        $payment->update([
            'status' => PaymentStatus::Refunded,
        ]);

        // record transaction
        PaymentTransaction::create([
            'payment_id' => $payment->id,
            'type' => 'refund',
            'amount' => $payment->amount,
            'currency' => $payment->currency ?? 'usd',
            'meta' => [
                'stripe_refund_id' => $refund->id ?? null,
            ],
        ]);

        BookingStatusService::changeStatus(
            $booking,
            BookingStatus::Cancelled,
            [
                'cancelled_at' => now(),
            ]
        );

        return $booking->fresh();
    }

    // $booking->update([
    //     'status' => BookingStatus::Cancelled
    // ]);
    BookingStatusService::changeStatus(
    $booking,
    BookingStatus::Cancelled
);

    return $booking->fresh();
}
}
