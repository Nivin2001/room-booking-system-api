<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use App\Models\Booking;
use App\Models\Space;
use App\Models\User;
use App\Policies\BookingPolicy;
use App\Policies\SpacePolicy;

class AuthServiceProvider extends ServiceProvider
{
    protected $policies = [
        Booking::class => BookingPolicy::class,
        Space::class => SpacePolicy::class,
    ];

    public function boot()
    {
        $this->registerPolicies();

        // Simple admin ability used by controllers for global admin actions
        Gate::define('admin', function (User $user) {
            return $user->role === 'admin';
        });
    }
}
