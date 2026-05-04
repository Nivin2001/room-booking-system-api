<?php

namespace App\Listeners;

use App\Events\BookingRejected;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendBookingRejectedNotification
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
       public function handle($event)
    {
        $event->booking->user->notify(
            new SendBookingRejectedNotification($event->booking)
        );
    }
}
