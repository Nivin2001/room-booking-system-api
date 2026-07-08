<?php
namespace App\Services;
use App\Enums\BookingStatus;
use App\Models\Booking;

class BookingStatusService
{
  public static function changeStatus(
    Booking $booking,
    BookingStatus $newStatus,
    array $attributes = []
): void {

    // $currentStatus = BookingStatus::from($booking->status);
    $currentStatus = $booking->status;

    if (! $currentStatus->canTransitionTo($newStatus)) {
        throw new \DomainException(
            "Invalid booking status transition: {$currentStatus->value} -> {$newStatus->value}"
        );
    }

    $booking->update(array_merge([
        'status' => $newStatus,
    ], $attributes));
}
}
