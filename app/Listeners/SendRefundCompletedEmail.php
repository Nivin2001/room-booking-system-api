<?php

namespace App\Listeners;

use App\Events\BookingApproved;
use App\Events\RefundCompleted;
use App\Mail\BookingApprovedMail;
use App\Mail\RefundCompletedMail;
use App\Notifications\RefundCompletedNotification;
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

    public $tries = 3;

  public $backoff = 5;
      public function handle(RefundCompleted $event)
    {
       $event->booking->user->notify(
        new RefundCompletedNotification(
            $event->booking,
            $event->payment
        )
    );
    }
}
