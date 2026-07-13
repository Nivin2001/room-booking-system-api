<?php

namespace App\Listeners;

use App\Events\BookingCreated;
use App\Mail\BookingCreatedMail;
use App\Models\Booking;
use App\Notifications\BookingCreatedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendBookingCreatedEmail implements ShouldQueue
{
    /**
     * Create the event listener.
     */

    /**
     * Handle the event.
     */
    //  public function handle(BookingCreated $event)
    // {
    //     \Log::info('BookingCreated Listener Fired');
    //     \Log::info('Sending mail to: '.$event->booking->user->email);
    //     Mail::to($event->booking->user->email)
    //         ->send(new BookingCreatedMail($event->booking));
    // }
     public $tries = 3; // 🔁 يحاول 3 مرات قبل ما يفشل

    public $backoff = 5; // ⏱️ ينتظر 5 ثواني بين كل محاولة

    public function handle(BookingCreated $event): void
{
    $event->booking->user->notify(
        new BookingCreatedNotification(
            $event->booking
        )
    );
}
}
