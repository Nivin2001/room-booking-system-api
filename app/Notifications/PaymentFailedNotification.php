<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentFailedNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public $booking,
        public $payment
    ) {}

    public function via($notifiable): array
    {
        return ['database','mail'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title' => 'Payment Failed',
            'message' => 'Your payment failed. Please try again.',
            'booking_id' => $this->booking->id,
            'payment_id' => $this->payment->id,
        ];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Payment Failed')
            ->line('Your payment failed.')
            ->line('Please try again.');
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
