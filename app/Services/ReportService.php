<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\User;
use App\Models\Payment;
use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;


class ReportService
{

    public function summary(): array
    {

        // 📅 Booking statistics

        $bookings = [
            'total' => Booking::count(),

            'confirmed' => Booking::where(
                'status',
                BookingStatus::Confirmed
            )->count(),


            'cancelled' => Booking::where(
                'status',
                BookingStatus::Cancelled
            )->count(),


            'rejected' => Booking::where(
                'status',
                BookingStatus::Rejected
            )->count(),


            'pending' => Booking::where(
                'status',
                BookingStatus::PendingStaffApproval
            )->count(),
        ];



        // 💰 Payment statistics

        $payments = [

            'revenue' => Payment::where(
                'status',
                PaymentStatus::Paid
            )->sum('amount'),


            'paid' => Payment::where(
                'status',
                PaymentStatus::Paid
            )->count(),


            'refunded' => Payment::where(
                'status',
                PaymentStatus::Refunded
            )->count(),

        ];



        // 👥 Customers

        $customers = User::whereHas('bookings')
            ->count();



        return [

            'bookings' => $bookings,

            'payments' => $payments,

            'customers' => $customers,

        ];
    }
}
