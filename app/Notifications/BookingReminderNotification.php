<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingReminderNotification extends Notification
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
    public function via($notifiable)
    {
        return ['database']; // ممكن تضيفي mail بعدين
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->line('The introduction to the notification.')
            ->action('Notification Action', url('/'))
            ->line('Thank you for using our application!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
        public function toArray($notifiable)
    {
        return [
            'message' => 'Reminder: Your booking starts in 1 hour',
            'space' => $this->booking->space->title,
            'start_time' => $this->booking->start_time,
        ];
    }
}
