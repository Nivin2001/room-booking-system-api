<?php
namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Space;
use Illuminate\Support\Carbon;

class AvailabilityService
{
    // public function isAvailable($spaceId, $start, $end): bool
    // {
    //     return !Booking::where('space_id', $spaceId)
    //         ->whereIn('status', [
    //             'pending_payment',
    //             'pending_staff_approval',
    //             'confirmed'
    //         ])
    //         ->where(function ($q) use ($start, $end) {
    //             $q->whereBetween('start_time', [$start, $end])
    //               ->orWhereBetween('end_time', [$start, $end])
    //               ->orWhere(function ($q2) use ($start, $end) {
    //                   $q2->where('start_time', '<=', $start)
    //                      ->where('end_time', '>=', $end);
    //               });
    //         })
    //         ->exists();
    // }
    public function isAvailable($spaceId, $start, $end, ?int $excludeBookingId = null): bool
{
    $query = Booking::where('space_id', $spaceId)
        ->whereIn('status', [
            BookingStatus::PendingPayment,
            BookingStatus::PendingStaffApproval,
            BookingStatus::Confirmed,
        ]);

    if ($excludeBookingId) {
        $query->where('id', '<>', $excludeBookingId);
    }

    $query->where(function ($q) use ($start, $end) {
        $q->where('start_time', '<', $end)
          ->where('end_time', '>', $start);
    });

    return ! $query->exists();
}
    // ✅ ADD THIS (missing method)
   public function getSlots($spaceId, $date)
{
    $space = Space::findOrFail($spaceId);

    $opening = $space->opening_hour ?? '08:00';
    $closing = $space->closing_hour ?? '23:00';

    [$openHour] = explode(':', $opening);
    [$closeHour] = explode(':', $closing);

    $slots = [];

    for ($hour = (int)$openHour; $hour < (int)$closeHour; $hour++) {
        $time = sprintf('%02d:00', $hour);

        $start = Carbon::parse($date . ' ' . $time);
        $end = $start->copy()->addHour();

        $slots[] = [
            'time' => $time,
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
            'available' => $this->isAvailable(
                $spaceId,
                $start,
                $end
            )
        ];
    }

    return $slots;
}
}
?>

