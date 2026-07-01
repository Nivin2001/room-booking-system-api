<?php

namespace App\Listeners;

use App\Events\BookingApproved;
use App\Events\RefundCompleted;
use App\Mail\BookingApprovedMail;
use App\Mail\RefundCompletedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;

class SendRefundCompletedEmail implements ShouldQueue
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


      public function handle(RefundCompleted $event)
    {
        Mail::to($event->booking->user->email)
            ->send(new RefundCompletedMail($event->booking, $event->refund));
    }
}
