<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Notifications\BookingReminderNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

class SendBookingReminderJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, Queueable;

    public int $tries = 5;

    public array $backoff = [30, 60, 120, 300];

    public int $uniqueFor = 7200;

    public function __construct(public readonly int $bookingId) {}

    public function uniqueId(): string
    {
        return 'booking-reminder:'.$this->bookingId;
    }

    public function handle(): void
    {
        DB::transaction(function (): void {
            $booking = Booking::query()
                ->with(['user', 'space'])
                ->lockForUpdate()
                ->find($this->bookingId);

            if (! $booking || ! $booking->user || (bool) $booking->reminder_sent) {
                return;
            }

            $notification = new BookingReminderNotification($booking);
            $alreadyStored = $booking->user->notifications()
                ->whereKey($notification->id)
                ->exists();

            if (! $alreadyStored) {
                // The current database-channel insert and sent flag commit atomically.
                $booking->user->notify($notification);
            }

            $booking->forceFill(['reminder_sent' => true])->save();
        }, 3);
    }

    public function failed(\Throwable $exception): void
    {
        logger()->warning('Booking reminder job exhausted its retries.', [
            'booking_id' => $this->bookingId,
            'exception' => class_basename($exception),
        ]);
    }
}
