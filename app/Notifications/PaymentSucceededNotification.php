<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentSucceededNotification extends Notification
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
            'title' => 'Payment Successful',
            'message' => 'Your payment was completed successfully.',
            'booking_id' => $this->booking->id,
            'payment_id' => $this->payment->id,
        ];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Payment Successful')
            ->line('Your payment has been completed successfully.')
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
