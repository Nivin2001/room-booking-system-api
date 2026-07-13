<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingCancelledNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */

    public function __construct(
        public $booking
    ) {}

    public function via($notifiable): array
    {
        return ['database','mail'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title' => 'Booking Cancelled',
            'message' => 'Your booking has been cancelled.',
            'booking_id' => $this->booking->id,
        ];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Booking Cancelled')
            ->line('Your booking has been cancelled.')
            ->line('Booking ID: '.$this->booking->id);
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            //
        ];
    }
}
