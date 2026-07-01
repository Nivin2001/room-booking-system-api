<?php

namespace App\Listeners;

use App\Events\BookingRejected;
use App\Mail\BookingRejectedMail;
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
        public function handle(BookingRejected $event)
    {
        Mail::to($event->booking->user->email)
            ->send(new BookingRejectedMail($event->booking, $event->reason));
    }
}
