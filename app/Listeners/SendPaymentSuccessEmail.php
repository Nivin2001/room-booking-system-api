<?php

namespace App\Listeners;

use App\Events\PaymentSucceeded;
use App\Mail\PaymentSuccessMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendPaymentSuccessEmail implements ShouldQueue
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
      public function handle(PaymentSucceeded $event): void
    {
        Mail::to($event->booking->user->email)
            ->send(new PaymentSuccessMail(
                $event->booking,
                $event->payment
            ));
    }
}

