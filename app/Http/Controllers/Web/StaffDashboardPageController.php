<?php

namespace App\Http\Controllers\Web;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Space;
use Illuminate\Http\Request;

class StaffDashboardPageController
{
    public function index(Request $request)
    {
        $staffId = $request->user()->id;
        $spaceIds = Space::query()->where('staff_id', $staffId)->select('id');
        $base = Booking::query()->whereIn('space_id', $spaceIds);
        $counts = [
            'pending_payment' => (clone $base)->where('status', BookingStatus::PendingPayment)->count(),
            'pending' => (clone $base)->where('status', BookingStatus::PendingStaffApproval)->count(),
            'confirmed' => (clone $base)->where('status', BookingStatus::Confirmed)->count(),
            'rejected' => (clone $base)->where('status', BookingStatus::Rejected)->count(),
            'upcoming' => (clone $base)->where('status', BookingStatus::Confirmed)->where('start_time', '>=', now())->count(),
        ];
        $upcoming = (clone $base)->with(['space', 'user'])
            ->where('status', BookingStatus::Confirmed)
            ->where('start_time', '>=', now())
            ->orderBy('start_time')->limit(6)->get();
        $assignedSpaces = $request->user()->assignedSpaces()
            ->with('categories')
            ->orderBy('title')
            ->orderBy('id')
            ->limit(4)
            ->get();

        return view('staff.dashboard', compact('counts', 'upcoming', 'assignedSpaces'));
    }
}
