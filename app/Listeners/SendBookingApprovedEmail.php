<?php

namespace App\Listeners;

use App\Events\BookingApproved;
use App\Mail\BookingApprovedMail;
use App\Models\Booking;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendBookingApprovedEmail implements ShouldQueue
{
    /**
     * Create the event listener.
     */
        // public Booking $booking;

    // public function __construct(Booking $booking)
    // {
    //     $this->booking = $booking;
    // }

    /**
     * Handle the event.
     */
      public $tries = 3; // 🔁 يحاول 3 مرات قبل ما يفشل

    public $backoff = 5; // ⏱️ ينتظر 5 ثواني بين كل محاولة

       public function handle(BookingApproved $event)
    {
        Mail::to($event->booking->user->email)
            ->send(new BookingApprovedMail($event->booking));
    }


}
