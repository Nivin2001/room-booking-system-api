<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Notifications\BookingReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;
use App\Jobs\SendBookingReminderJob;

class SendBookingReminders extends Command
{
    protected $signature = 'bookings:reminders';
    protected $description = 'Send reminders for upcoming bookings';

    public function handle()
    {
        $now = Carbon::now();
        $targetTime = $now->copy()->addHour();

        $bookings = Booking::where('status', 'confirmed')
            ->where('reminder_sent', false)
         ->whereBetween('start_time', [
    $targetTime->copy()->subMinutes(30),  // وسّع المجال
    $targetTime->copy()->addMinutes(30)
])
            ->get();

        foreach ($bookings as $booking) {
            SendBookingReminderJob::dispatch($booking);
        }

        $this->info('Reminders dispatched: ' . $bookings->count());
    }
}
