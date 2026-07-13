<?php

namespace App\Listeners;

use App\Events\BookingRejected;
use App\Mail\BookingRejectedMail;
use App\Notifications\BookingRejectedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendBookingRejectedEmail implements ShouldQueue
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
        public $tries = 3;

    public $backoff = 5;

        public function handle(BookingRejected $event)
    {
        $event->booking->user->notify(
        new BookingRejectedNotification(
            $event->booking,
            $event->reason
        )
    );
    }
}
