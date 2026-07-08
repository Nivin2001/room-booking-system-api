<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Booking;
use App\Enums\BookingStatus;
use App\Services\BookingStatusService;

class ExpirePendingBookings extends Command
{
    protected $signature = 'bookings:expire';

    protected $description = 'Expire unpaid bookings';

    public function handle(): int
    {
        $bookings = Booking::where(
                'status',
                BookingStatus::PendingPayment
            )
            ->where(
                'expires_at',
                '<=',
                now()
            )
            ->get();


        foreach ($bookings as $booking) {

            BookingStatusService::changeStatus(
                $booking,
                BookingStatus::Expired
            );

        }


        $this->info(
            $bookings->count().' bookings expired'
        );


        return Command::SUCCESS;
    }

// class ExpirePendingBookings extends Command
// {
//     /**
//      * The name and signature of the console command.
//      *
//      * @var string
//      */
//     protected $signature = 'app:expire-pending-bookings';

//     /**
//      * The console command description.
//      *
//      * @var string
//      */
//     protected $description = 'Command description';

//     /**
//      * Execute the console command.
//      */
//     public function handle()
//     {
//         //
//     }
}
