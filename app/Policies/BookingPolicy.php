<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BookingPolicy
{
    /**
     * Determine whether the user can view any models.
     */

    public function viewAny(User $user): bool
{
    return true;
}

/**
 * Customer can view his booking.
 * Staff can view bookings for spaces he owns.
 * Admin can view everything.
 */
public function view(User $user, Booking $booking): bool
{
    return $user->role === 'admin'
        || $booking->user_id === $user->id
        || $booking->space->created_by === $user->id;
}

/**
 * Any authenticated customer can create bookings.
 */
public function create(User $user): bool
{
    return $user->role === 'customer';
}

/**
 * Customer can update only his pending booking.
 */
public function update(User $user, Booking $booking): bool
{
    return $booking->user_id === $user->id
        && in_array($booking->status, ['pending_payment', 'pending_staff_approval']);
}

/**
 * Admin only.
 */
public function delete(User $user, Booking $booking): bool
{
    return $user->role === 'admin';
}

public function restore(User $user, Booking $booking): bool
{
    return $user->role === 'admin';
}

public function forceDelete(User $user, Booking $booking): bool
{
    return $user->role === 'admin';
}

/**
 * Customer cancels his own booking.
 */
public function cancel(User $user, Booking $booking): bool
{
    return $user->id === $booking->user_id;
}

/**
 * Staff approves bookings for spaces he manages.
 */
public function approve(User $user, Booking $booking): bool
{
    return $user->role === 'staff'
        && $booking->space->created_by === $user->id;
}

/**
 * Staff rejects bookings for spaces he manages.
 */
public function reject(User $user, Booking $booking): bool
{
    return $user->role === 'staff'
        && $booking->space->created_by === $user->id;
}
}
