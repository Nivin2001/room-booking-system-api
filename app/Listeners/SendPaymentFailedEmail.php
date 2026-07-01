<?php

namespace App\Listeners;

use App\Events\PaymentFailed;
use App\Mail\PaymentFailedMail;
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

    public function handle(PaymentFailed $event)
    {
        Mail::to($event->booking->user->email)
            ->send(new PaymentFailedMail($event->booking, $event->payment));
    }
}
