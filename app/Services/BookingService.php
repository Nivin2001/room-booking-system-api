<?php
namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Services\AvailabilityService;
use App\Services\PricingService;
use Illuminate\Support\Facades\DB;

class BookingService
{
    public function __construct(
        protected AvailabilityService $availabilityService,
        protected PricingService $pricingService
    ) {}

    public function createBooking($user, array $data): Booking
    {
        return DB::transaction(function () use ($user, $data) {

            // 1. Check availability
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
                'status' =>BookingStatus::PendingPayment,
                'expires_at' => now()->addMinutes(10),
            ]);
        });
    }

    public function getSlots($spaceId, $date)
    {
        return app(AvailabilityService::class)->getSlots($spaceId, $date);
    }

    public function getBooking($id)
    {
        return Booking::with(['space', 'customer'])->findOrFail($id);
    }

    public function getAllBookings()
    {
        return Booking::with(['space', 'customer'])->latest()->get();
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

        $isAvailable = $this->availabilityService->isAvailable(
            $booking->space_id,
            $data['start_time'],
            $data['end_time']
        );

        if (!$isAvailable) {
            throw new \Exception('This time slot is already booked');
        }

        $booking->update([
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
        ]);

        return $booking->fresh();
    }

    public function cancelBooking($id): Booking
    {
        $booking = Booking::findOrFail($id);

        if ($booking->status === BookingStatus::Cancelled) {
            throw new \Exception('Already cancelled');
        }

        if (now()->greaterThan($booking->start_time)) {
            throw new \Exception('Cannot cancel past booking');
        }

        $booking->update([
            'status' => BookingStatus::Cancelled
        ]);

        return $booking->fresh();
    }
}
