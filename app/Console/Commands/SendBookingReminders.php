<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Notifications\BookingReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendBookingReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bookings:reminders';

    protected $description = 'Send reminders for upcoming bookings';

    public function handle()
    {
        $now = Carbon::now();
        $targetTime = $now->copy()->addHour();

        $bookings = Booking::whereBetween('start_time', [
                $targetTime->copy()->startOfMinute(),
                $targetTime->copy()->endOfMinute()
            ])
            ->where('reminder_sent', false)
            ->with('user')
            ->get();

        foreach ($bookings as $booking) {
            $booking->user->notify(new BookingReminderNotification($booking));

            $booking->update([
                'reminder_sent' => true
            ]);
        }

        $this->info('Reminders sent successfully');
    }

}
