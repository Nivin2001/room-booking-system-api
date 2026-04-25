<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class  BookingCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

        protected $booking;
    /**
     * Create a new notification instance.
     */
     public function __construct($booking)
    {
        $this->booking = $booking;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail','database'];
    }

    /**
     * Get the mail representation of the notification.
     */
      public function toMail($notifiable)
    {
        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject('Booking Created ✅')
            ->line('Your booking has been created successfully.')
            ->line('Space ID: ' . $this->booking->space_id)
            ->line('Start: ' . $this->booking->start_time)
            ->line('End: ' . $this->booking->end_time)
            ->line('Thank you for using our app!');
    }
       public function toDatabase($notifiable)
    {
        return [
            'message' => 'Your booking has been created in db',
            'space_id' => $this->booking->space_id,
            'time' => $this->booking->start_time
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
