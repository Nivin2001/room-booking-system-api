<?php

namespace App\Providers;

use App\Events\BookingApproved;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use App\Events\BookingCreated;
use App\Events\BookingRejected;
use App\Listeners\SendBookingApprovedNotification;
use App\Listeners\SendBookingNotification;
use App\Listeners\SendBookingRejectedNotification;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        BookingCreated::class => [
            SendBookingNotification::class,
        ],
         BookingApproved::class => [
      SendBookingApprovedNotification::class,
    ],
      BookingRejected::class => [
        SendBookingRejectedNotification::class,
    ],
    ];

    public function boot(): void
    {
        parent::boot();
    }
}
