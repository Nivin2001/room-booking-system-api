<?php

namespace App\Providers;

use App\Events\BookingApproved;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use App\Events\BookingCreated;
use App\Events\BookingRejected;


class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        // BookingCreated::class => [
        //     SendBookingNotification::class,
        // ],

    \App\Events\BookingCreated::class => [
        \App\Listeners\SendBookingCreatedEmail::class,
    ],
    \App\Events\BookingApproved::class => [
    \App\Listeners\SendBookingApprovedEmail::class,
],

\App\Events\BookingRejected::class => [
    \App\Listeners\SendBookingRejectedEmail::class,
],
    ];

    public function boot(): void
    {
        parent::boot();
    }
}
