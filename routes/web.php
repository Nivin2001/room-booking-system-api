<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\WebAuthController;
use App\Http\Controllers\Web\SpacePageController;
use App\Http\Controllers\Web\BookingPageController;
use App\Http\Controllers\Web\MyBookingsPageController;
use App\Http\Controllers\Web\StaffDashboardPageController;
use App\Http\Controllers\Web\StaffBookingPageController;
use App\Http\Controllers\Web\AdminDashboardPageController;
use App\Http\Middleware\WebAuthRateLimit;

Route::get('/', [SpacePageController::class, 'index'])->name('spaces.index');
Route::get('/spaces/{space}', [SpacePageController::class, 'show'])->name('spaces.show');

Route::middleware('guest:web')->group(function () {
    Route::get('/login', [WebAuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [WebAuthController::class, 'login'])->middleware(WebAuthRateLimit::class)->name('login.store');
    Route::get('/register', [WebAuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [WebAuthController::class, 'register'])->middleware(WebAuthRateLimit::class)->name('register.store');
});

Route::middleware('auth:web')->group(function () {
    Route::post('/logout', [WebAuthController::class, 'logout'])->name('logout');
    Route::get('/my-bookings', [MyBookingsPageController::class, 'index'])->name('customer.bookings.index');
    Route::get('/my-bookings/{booking}', [MyBookingsPageController::class, 'show'])->name('customer.bookings.show');
    Route::post('/my-bookings/{booking}/cancel', [MyBookingsPageController::class, 'cancel'])->name('customer.bookings.cancel');
    Route::get('/spaces/{space}/book', [BookingPageController::class, 'create'])->name('spaces.bookings.create');
    Route::post('/spaces/{space}/book', [BookingPageController::class, 'store'])->name('spaces.bookings.store');
    Route::get('/booking-confirmation/{booking}', [BookingPageController::class, 'confirmation'])->name('spaces.bookings.confirmation');
    Route::post('/booking-confirmation/{booking}/checkout', [BookingPageController::class, 'startCheckout'])->name('spaces.bookings.checkout');
    Route::get('/booking-confirmation/{booking}/payment-success', [BookingPageController::class, 'paymentSuccess'])->name('spaces.bookings.payment-success');
    Route::get('/booking-confirmation/{booking}/payment-cancelled', [BookingPageController::class, 'paymentCancelled'])->name('spaces.bookings.payment-cancelled');
});

Route::middleware(['auth:web', 'role:staff'])->prefix('staff')->name('staff.')->group(function () {
    Route::get('/dashboard', [StaffDashboardPageController::class, 'index'])->name('dashboard');
    Route::get('/spaces', [StaffBookingPageController::class, 'spaces'])->name('spaces.index');
    Route::get('/bookings', [StaffBookingPageController::class, 'index'])->name('bookings.index');
    Route::get('/bookings/{booking}', [StaffBookingPageController::class, 'show'])->name('bookings.show');
    Route::post('/bookings/{booking}/approve', [StaffBookingPageController::class, 'approve'])->name('bookings.approve');
    Route::post('/bookings/{booking}/reject', [StaffBookingPageController::class, 'reject'])->name('bookings.reject');
});

Route::middleware(['auth:web', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [AdminDashboardPageController::class, 'index'])->name('dashboard');
    Route::get('/spaces', [AdminDashboardPageController::class, 'spaces'])->name('spaces.index');
    Route::get('/spaces/create', [AdminDashboardPageController::class, 'createSpace'])->name('spaces.create');
    Route::post('/spaces', [AdminDashboardPageController::class, 'storeSpace'])->name('spaces.store');
    Route::get('/spaces/{space}/edit', [AdminDashboardPageController::class, 'editSpace'])->name('spaces.edit');
    Route::put('/spaces/{space}', [AdminDashboardPageController::class, 'updateSpace'])->name('spaces.update');
    Route::patch('/spaces/{space}/availability', [AdminDashboardPageController::class, 'toggleSpace'])->name('spaces.toggle');
    Route::delete('/spaces/{space}', [AdminDashboardPageController::class, 'deleteSpace'])->name('spaces.delete');
    Route::get('/categories', [AdminDashboardPageController::class, 'categories'])->name('categories.index');
    Route::post('/categories', [AdminDashboardPageController::class, 'storeCategory'])->name('categories.store');
    Route::put('/categories/{category}', [AdminDashboardPageController::class, 'updateCategory'])->name('categories.update');
    Route::delete('/categories/{category}', [AdminDashboardPageController::class, 'deleteCategory'])->name('categories.delete');
    Route::get('/bookings', [AdminDashboardPageController::class, 'bookings'])->name('bookings.index');
    Route::get('/bookings/{booking}', [AdminDashboardPageController::class, 'showBooking'])->name('bookings.show');
    Route::get('/payments', [AdminDashboardPageController::class, 'payments'])->name('payments.index');
});
