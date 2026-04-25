<?php

namespace App\Providers;

use App\Models\Booking;
use App\Models\Space;
use App\Policies\BookingPolicy;
use App\Policies\SpacePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //

    Gate::policy(Space::class, SpacePolicy::class);
    Gate::policy(Booking::class, BookingPolicy::class);


    }
}
