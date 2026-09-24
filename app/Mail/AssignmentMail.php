<?php

namespace App\Mail;

use App\Models\PitboardNotification;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class AssignmentMail extends Mailable
{
    public function __construct(public PitboardNotification $notification) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('pitboard_notifications.from_address'), 'BBQuality Pitboard'),
            subject: 'BBQuality Pitboard · '.($this->notification->kind === 'task' ? 'Nieuwe taak voor jou' : 'Toegevoegd aan een project'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'emails.assignment', with: [
            'link' => rtrim(config('pitboard_notifications.url'), '/').'/?melding='.$this->notification->id,
        ]);
    }
}
