<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingRejected extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    protected $booking;

    public function __construct($booking)
    {
        $this->booking = $booking;
    }

    public function via($notifiable)
    {
        return ['mail','database'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Booking Rejected ❌')
            ->line('Your booking has been rejected.')
            ->line('Space ID: ' . $this->booking->space_id)
            ->line('Start: ' . $this->booking->start_time)
            ->line('End: ' . $this->booking->end_time);
    }

           public function toDatabase($notifiable)
    {
        return [
            'message' => 'Your booking has been rejected',
            'space_id' => $this->booking->space_id,
            'time' => $this->booking->start_time,
            'time' => $this->booking->end_time
        ];
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
