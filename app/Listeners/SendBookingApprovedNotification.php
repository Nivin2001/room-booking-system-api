<?php

namespace App\Listeners;

use App\Events\BookingApproved;
use App\Notifications\BookingApproved as BookingApprovedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;


class SendBookingApprovedNotification implements ShouldQueue
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
            new BookingApprovedNotification($event->booking)
        );
    }

}
