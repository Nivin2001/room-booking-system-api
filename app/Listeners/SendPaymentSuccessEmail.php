<?php

namespace App\Listeners;

use App\Events\PaymentSucceeded;
use App\Mail\PaymentSuccessMail;
use App\Notifications\PaymentSucceededNotification;
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
        public $tries = 3;

    public $backoff = 5;

      public function handle(PaymentSucceeded $event): void
    {
           $event->booking->user->notify(
        new PaymentSucceededNotification(
            $event->booking,
            $event->payment
        ));
    }
}

