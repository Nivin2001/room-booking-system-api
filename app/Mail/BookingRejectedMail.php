<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
        public function __construct(
        public Booking $booking
    ) {}
//     public $booking;
// public $reason;

// public function __construct($booking, $reason)
// {
//     $this->booking = $booking;
//     $this->reason = $reason;
// }
 public function build()
{
    return $this
        ->subject('Booking Rejected')
        ->html("
            <h2>Booking Rejected</h2>
            <p>Your booking #{$this->booking->id} has been rejected.</p>
            // <p><strong>Reason:</strong> {$this->booking->rejection_reason}</p>
               <p> Refund process has started. </p>
        ");
}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Booking Rejected Mail',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'view.name',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
