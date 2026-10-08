<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RiderPasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $otp,
        public readonly string $firstName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'AVISO — Reset Your Password',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.rider-password-reset',
            with: [
                'otp'       => $this->otp,
                'firstName' => $this->firstName,
            ],
        );
    }
}
