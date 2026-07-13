<?php
namespace App\Services;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Space;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardService
{
      public function stats(): array
    {
        // 📊 Total bookings
        $totalBookings = Booking::count();


        // ❌ Cancelled bookings
        $cancelledBookings = Booking::where(
            'status',
            BookingStatus::Cancelled
        )->count();


        // 👥 Total customers
        $totalCustomers = User::whereHas('bookings')
            ->count();


        // 💰 Total revenue (paid only)
        $totalRevenue = Booking::whereHas('payment', function ($query) {
                $query->where(
                    'status',
                    PaymentStatus::Paid
                );
            })
            ->sum('total_amount');


        // 🏆 Most booked space
        $mostBookedSpace = Booking::select(
                'space_id',
                DB::raw('COUNT(*) as bookings_count')
            )
            ->groupBy('space_id')
            ->orderByDesc('bookings_count')
            ->with('space:id,title')
            ->first();


        // 📅 Today's bookings
        $bookingsToday = Booking::whereDate(
            'created_at',
            Carbon::today()
        )->count();


        return [

            'total_bookings' => $totalBookings,

            'bookings_today' => $bookingsToday,

            'cancelled_bookings' => $cancelledBookings,

            'total_customers' => $totalCustomers,

            'total_revenue' => $totalRevenue,

            'most_booked_space' => $mostBookedSpace,

        ];
    }
}
