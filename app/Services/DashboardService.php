<?php
namespace App\Services;

use App\Models\Booking;
use App\Models\Space;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function stats()
    {
        // 📊 total bookings
        $totalBookings = Booking::count();

        // 📅 bookings today
        $bookingsToday = Booking::whereDate('created_at', Carbon::today())->count();

        // 💰 total revenue
        $totalRevenue = Booking::sum('total_price');

        // 🔥 most booked spaces
        $topSpaces = Booking::select('space_id', DB::raw('count(*) as total'))
            ->groupBy('space_id')
            ->orderByDesc('total')
            ->with('space')
            ->take(5)
            ->get();

        return [
            'total_bookings' => $totalBookings,
            'bookings_today' => $bookingsToday,
            'total_revenue' => $totalRevenue,
            'top_spaces' => $topSpaces,
        ];
    }
}
