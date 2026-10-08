<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param string $otp   The plain-text 6-digit OTP (only sent in email, never stored plain)
     * @param string $email The recipient email for display in the template
     */
    public function __construct(
        public readonly string $otp,
        public readonly string $email,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'AVISO — Your Password Reset Code',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.password-reset-otp',
            with: [
                'otp'   => $this->otp,
                'email' => $this->email,
            ],
        );
    }
}
