<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingApprovedNotification extends Notification implements ShouldQueue
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
            ->subject('Booking Approved ✅')
            ->line('Your booking has been approved!')
            ->line('Space ID: ' . $this->booking->space_id)
            ->line('Start: ' . $this->booking->start_time)
            ->line('End: ' . $this->booking->end_time);
    }
         public function toDatabase($notifiable)
    {
        return [
            'booking_id' => $this->booking->id,
            'message' => 'Your booking has been approved',
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

    public function toArray($notifiable)
    {
        return [
            'booking_id' => $this->booking->id,

            'title' => 'Booking Approved',

            'message' => 'Your booking has been approved.',

            'status' => 'confirmed',
        ];
    }
}
