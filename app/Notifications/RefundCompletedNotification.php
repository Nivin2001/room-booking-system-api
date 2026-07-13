<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RefundCompletedNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
     public function __construct(
        public $booking,
        public $refund
    ) {}

    public function via($notifiable): array
    {
        return ['database','mail'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title' => 'Refund Completed',
            'message' => 'Your refund has been completed successfully.',
            'booking_id' => $this->booking->id,
            'refund_id' => $this->refund->id,
        ];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Refund Completed')
            ->line('Your refund has been completed successfully.')
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
