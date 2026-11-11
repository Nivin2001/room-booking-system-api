<?php

use App\Http\Controllers\API\Admin\BookingController as AdminBookingController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\Admin\CategoryController;
use App\Http\Controllers\API\Admin\DashboardController;
use App\Http\Controllers\API\Admin\ReportController;
use App\Http\Controllers\API\Admin\UserController;
use App\Http\Controllers\API\Customer\BookingController;
use App\Http\Controllers\API\Customer\PaymentController;
use App\Http\Controllers\API\Staff\BookingController as StaffBookingController;
use App\Http\Controllers\API\Staff\SpaceController;
use App\Http\Controllers\API\StripeWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// public route
Route::prefix('v1')->group(function () {
    // 🔓 Public
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);
    Route::get('/payment/success', function (Request $request) {
        return response()->json([
            'message' => 'Payment completed successfully. Please wait confirmation.'
        ]);
    });

    Route::get('/payment/cancel', function () {
        return response()->json([
            'message' => 'Payment cancelled.'
        ]);
    });

    // 🔐 Protected route // Customer

    Route::middleware(['auth:sanctum', 'role:customer'])
        ->prefix('customer')
        ->group(function () {

            // Spaces
            Route::get('spaces', [SpaceController::class, 'index']);
            Route::get('spaces/{id}', [SpaceController::class, 'show']);
            Route::get('spaces/{id}/availability', [SpaceController::class, 'availability']);
            Route::get('spaces/{id}/slots', [BookingController::class, 'slots']);

            // Bookings
            Route::post('bookings', [BookingController::class, 'store']);
            Route::get('bookings', [BookingController::class, 'myBookings']);

            // 🔥 Booking Details
            Route::get('bookings/{id}', [BookingController::class, 'show']);

            // 🔥 Booking Status
            Route::get('bookings/{id}/status', [BookingController::class, 'status']);

            Route::put('bookings/{id}', [BookingController::class, 'update']);
            Route::patch('bookings/{id}/cancel', [BookingController::class, 'cancelBooking']);

            // Payments
            Route::post('payments/checkout', [PaymentController::class, 'checkout']);

            // Notifications
            Route::get('notifications', [UserController::class, 'notifications']);
            Route::post(
                'notifications/{notification}/read',
                [UserController::class, 'markNotificationAsRead']
            );

            Route::post(
                'notifications/read-all',
                [UserController::class, 'markAllNotificationsAsRead']
            );
        });

    // staff route protected by auth and staff middleware
    Route::middleware(['auth:sanctum', 'role:staff'])->prefix('staff')->group(function () {

        // 📋 Bookings
        Route::get('/bookings', [StaffBookingController::class, 'index']);
        Route::patch('/bookings/{id}/approve', [StaffBookingController::class, 'approve']);
        Route::patch('/bookings/{id}/reject', [StaffBookingController::class, 'reject']);

        // Staff may read their assigned spaces; space configuration remains admin-only by policy.
        Route::get('spaces', [SpaceController::class, 'index']);
        Route::post('/spaces', [SpaceController::class, 'store']);
        Route::put('/spaces/{id}', [SpaceController::class, 'update']);
        Route::delete('/spaces/{id}', [SpaceController::class, 'destroy']);
        // Connect the space with the sutiable catgory like space A Meeting room
        Route::post('spaces/{id}/categories', [SpaceController::class, 'syncCategories']);
        // Handle Images (Spaite Media Libary)
        Route::post('spaces/{space}/upload-image', [SpaceController::class, 'uploadImage']);
        Route::post('spaces/{space}/upload-images', [SpaceController::class, 'uploadMultipleImages']);
        Route::delete('media/{id}', [SpaceController::class, 'deleteImage']);
        Route::post('media/{id}/set-main', [SpaceController::class, 'setMainImage']);
    });
    Route::get('media/{id}/download', [SpaceController::class, 'downloadImage']);

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

        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::get('/reports/summary', [ReportController::class, 'summary']);
    });
});
