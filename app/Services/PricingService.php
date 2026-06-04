<?php
namespace App\Services;

use App\Models\Space;
use Carbon\Carbon;

class PricingService
{
//     public function calculatePrice($spaceId, $start, $end)
//     {
//         $space = Space::findOrFail($spaceId);

//         $start = Carbon::parse($start);
//         $end = Carbon::parse($end);

//         $hours = $end->diffInMinutes($start) / 60;

//         return $hours * $space->price_per_hour;
//     }
// }
public function calculatePrice($spaceId, $start, $end)
{
    $start = \Carbon\Carbon::parse($start);
    $end = \Carbon\Carbon::parse($end);

    if ($end->lessThanOrEqualTo($start)) {
        throw new \Exception("Invalid time range");
    }

    $hours = $start->diffInMinutes($end) / 60;

    $space = \App\Models\Space::findOrFail($spaceId);

    return abs($hours * $space->price_per_hour);
}
}
?>
