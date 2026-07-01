<?php

namespace App\Listeners;

use App\Events\BookingCancelled;
use App\Mail\BookingCancelledMail;
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
    public function handle(BookingCancelled $event)
    {
        Mail::to($event->booking->user->email)
            ->send(new BookingCancelledMail($event->booking));
    }
}
