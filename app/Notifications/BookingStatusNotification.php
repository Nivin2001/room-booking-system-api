<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingStatusNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public Booking $booking,
        public string $oldStatus,
        public string $newStatus
    ) {}

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable)
    {
        return match ($this->newStatus) {

            'approved' => (new MailMessage)
                ->subject('Booking Approved')
                ->line('Your booking has been approved.'),

            'rejected' => (new MailMessage)
                ->subject('Booking Rejected')
                ->line('Your booking has been rejected.'),

            'cancelled' => (new MailMessage)
                ->subject('Booking Cancelled')
                ->line('Your booking has been cancelled.'),

            default => (new MailMessage)
                ->subject('Booking Updated')
                ->line('Your booking status has been updated.'),
        };
    }

    public function toDatabase($notifiable)
    {
        return [
            'booking_id' => $this->booking->id,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'message' => "Booking changed to {$this->newStatus}",
        ];
    }
}
