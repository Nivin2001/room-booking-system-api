<?php

use App\Http\Controllers\Api\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\Api\Admin\CategoryController;
use App\Http\Controllers\API\Admin\UserController;
use App\Http\Controllers\Api\Customer\BookingController;
use App\Http\Controllers\Api\Staff\BookingController as StaffBookingController;
use App\Http\Controllers\Api\Staff\SpaceController;

use Illuminate\Support\Facades\Route;
  // public route
Route::prefix('v1')->group(function () {
    Route::get('/test-mail', [AdminBookingController::class, 'testMail']);
    // 🔓 Public
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);


    // 🔐 Protected route
    // customer route protected by auth and customer middleware
    Route::middleware('auth:sanctum', 'role:customer')
        ->prefix('customer')->group(function () {
            // Space Modrations
            Route::get('spaces', [SpaceController::class, 'index']);
            Route::get('spaces/{id}', [SpaceController::class, 'show']);
            Route::get('spaces/{id}/availability', [SpaceController::class, 'availability']);
            Route::get('spaces/{id}/slots', [BookingController::class, 'slots']);
            Route::post('bookings', [BookingController::class, 'store']);
            // 📋 My bookings
            Route::get('my-bookings', [BookingController::class, 'myBookings']);
            // ❌ Cancel booking
            Route::post('bookings/{id}/cancel', [BookingController::class, 'cancelBooking']);
            // notification
            Route::get('notifications', [UserController::class, 'notifications']);
        });

          // staff route protected by auth and staff middleware
    Route::middleware(['auth:sanctum', 'role:staff'])->prefix('staff')->group(function () {

        // 📋 Bookings
        Route::get('/bookings', [StaffBookingController::class, 'index']);
        Route::patch('/bookings/{id}/approve', [StaffBookingController::class, 'approve']);
        Route::patch('/bookings/{id}/reject', [StaffBookingController::class, 'reject']);

        // 🏢 Manage Spaces (As staff can apply crud in this modules)
        Route::get('spaces', [SpaceController::class, 'index']);
        Route::post('/spaces', [SpaceController::class, 'store']);
        Route::put('/spaces/{id}', [SpaceController::class, 'update']);
        Route::delete('/spaces/{id}', [SpaceController::class, 'destroy']);
        // Connect the space with the sutiable catgory like space A Meeting room
        Route::post('spaces/{id}/categories', [SpaceController::class, 'syncCategories']);
    });

    // 👑 Admin
    // admin route protected by auth and admin middleware
    Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {

        // 👤 Manage Users
        Route::get('users', [UserController::class, 'index']);
        Route::patch('users/{id}/role', [UserController::class, 'changeRole']);
        Route::delete('users/{id}', [UserController::class, 'destroy']);

        // Manage Categories
        Route::get('/categories', [CategoryController::class, 'index']);
        Route::post('/categories', [CategoryController::class, 'store']);
        Route::put('/categories/{id}', [CategoryController::class, 'update']);
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy']);
        Route::get('bookings', [AdminBookingController::class, 'index']);
    });
});
