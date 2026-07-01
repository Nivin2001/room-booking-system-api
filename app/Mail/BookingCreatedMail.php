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

class BookingCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
        public function __construct(
        public Booking $booking
    ) {}

 public function build()
{
    return $this
        ->subject('Booking Created Successfully')
        ->html("
            <h2>Hello {$this->booking->user->name},</h2>

            <p>Your booking was created successfully 🎉</p>

            <hr>

            <p><strong>Space ID:</strong> {$this->booking->space_id}</p>
            <p><strong>Start:</strong> {$this->booking->start_time}</p>
            <p><strong>End:</strong> {$this->booking->end_time}</p>
            <p><strong>Status:</strong> {$this->booking->status}</p>

            <hr>

            <p>Thank you for using our app!</p>
        ");
}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Booking Created Mail',
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
