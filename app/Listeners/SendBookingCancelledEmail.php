<?php

namespace App\Listeners;

use App\Events\BookingCancelled;
use App\Mail\BookingCancelledMail;
use App\Notifications\BookingCancelledNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use SendGrid\Mail\Mail;

class SendBookingCancelledEmail implements ShouldQueue
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

    public function handle(BookingCancelled $event)
    {
         $event->booking->user->notify(
        new BookingCancelledNotification($event->booking)
    );
    }
}
