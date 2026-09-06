<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $url, public string $name) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Redefinição de senha — Eventos IPCCG');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.reset-password');
    }
}
