<?php
namespace App\Policies;

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
            || ($booking->space && $booking->space->created_by === $user->id);
    }

    public function create(User $user): bool
    {
        return $user->role === 'customer';
    }

    public function update(User $user, Booking $booking): bool
    {
        return $booking->user_id === $user->id
            && in_array($booking->status, ['pending_payment', 'pending_staff_approval']);
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
        return $user->role === 'staff' && $booking->space && $booking->space->created_by === $user->id;
    }

    public function reject(User $user, Booking $booking): bool
    {
        return $user->role === 'staff' && $booking->space && $booking->space->created_by === $user->id;
    }
}
