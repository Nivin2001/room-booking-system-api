<?php

namespace App\Listeners;

use App\Events\PaymentFailed;
use App\Mail\PaymentFailedMail;
use App\Notifications\PaymentFailedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendPaymentFailedEmail implements ShouldQueue
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


    public function handle(PaymentFailed $event)
    {
    $event->booking->user->notify(
        new PaymentFailedNotification(
            $event->booking,
            $event->payment
        )
    );
    }
}
