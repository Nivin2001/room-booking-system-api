<?php

namespace App\Listeners;

use App\Events\BookingApproved;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendBookingApprovedNotification
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
            new SendBookingApprovedNotification($event->booking)
        );
    }

}
