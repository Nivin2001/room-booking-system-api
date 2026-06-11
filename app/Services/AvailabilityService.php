<?php
namespace App\Services;

use App\Models\Booking;
use Illuminate\Support\Carbon;

class AvailabilityService
{
    public function isAvailable($spaceId, $start, $end): bool
    {
        return !Booking::where('space_id', $spaceId)
            ->whereIn('status', [
                'pending_payment',
                'pending_staff_approval',
                'confirmed'
            ])
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('start_time', [$start, $end])
                  ->orWhereBetween('end_time', [$start, $end])
                  ->orWhere(function ($q2) use ($start, $end) {
                      $q2->where('start_time', '<=', $start)
                         ->where('end_time', '>=', $end);
                  });
            })
            ->exists();
    }
    // ✅ ADD THIS (missing method)
   public function getSlots($spaceId, $date)
{
    $slots = [];

    for ($hour = 8; $hour < 24; $hour++) {

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

