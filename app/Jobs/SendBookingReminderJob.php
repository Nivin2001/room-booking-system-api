<?php

namespace App\Jobs;

use App\Models\Booking;
use App\Notifications\BookingReminderNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendBookingReminderJob implements ShouldQueue
{
    use Queueable;
        protected $booking;

    /**
     * Create a new job instance.
     */


    public function __construct(Booking $booking)
    {
        $this->booking = $booking;
    }


    /**
     * Execute the job.
     */

    public function handle()
{
    $booking = $this->booking->fresh();

    if (!$booking || !$booking->user) {
        return;
    }

    $booking->user->notify(
        new BookingReminderNotification($booking)
    );

    $booking->update([
        'reminder_sent' => true
    ]);
}
    //  public function handle()
    // {
    //     if (!$this->booking->user) return;

    //     $this->booking->user->notify(
    //         new BookingReminderNotification($this->booking)
    //     );

    //     $this->booking->update([
    //         'reminder_sent' => true
    //     ]);
    // }
}
