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

        for ($hour = 8; $hour < 20; $hour++) {

            $time = str_pad($hour, 2, '0', STR_PAD_LEFT) . ':00';

            $start = Carbon::parse($date . ' ' . $time);
            $end = (clone $start)->addHour();

            $available = $this->isAvailable($spaceId, $start, $end);

            $slots[] = [
                'time' => $time,
                'start' => $start,
                'end' => $end,
                'available' => $available
            ];
        }

        return $slots;
    }
}
?>

