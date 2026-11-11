<?php
namespace App\Policies;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;

class BookingPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Booking $booking): bool
    {
        return $user->role === 'admin'
            || $booking->user_id === $user->id
            || ($user->role === 'staff' && $booking->space && (int) $booking->space->staff_id === (int) $user->id);
    }

    public function create(User $user): bool
    {
        return $user->role === 'customer';
    }

    public function update(User $user, Booking $booking): bool
    {
        return $booking->user_id === $user->id
            && in_array($booking->status, [
                BookingStatus::PendingPayment,
                BookingStatus::PendingStaffApproval,
            ], true);
    }

    public function delete(User $user, Booking $booking): bool
    {
        return $user->role === 'admin';
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $user->id === $booking->user_id || $user->role === 'admin';
    }

    public function approve(User $user, Booking $booking): bool
    {
        return $user->role === 'staff' && $booking->space && (int) $booking->space->staff_id === (int) $user->id;
    }

    public function reject(User $user, Booking $booking): bool
    {
        return $user->role === 'staff' && $booking->space && (int) $booking->space->staff_id === (int) $user->id;
    }
}
