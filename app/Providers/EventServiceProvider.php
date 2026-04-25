<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use App\Events\BookingCreated;
use App\Listeners\SendBookingNotification;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        BookingCreated::class => [
            SendBookingNotification::class,
        ],
    ];

    public function boot(): void
    {
        parent::boot();
    }
}
