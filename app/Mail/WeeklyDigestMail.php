<?php

namespace App\Mail;

use App\Services\Mail\DigestSummary;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Task 33's weekly digest — built and sent SYNCHRONOUSLY from within
 * `SendDigestEmailJob::handle()`/`DigestService::sendIfDue()`, which is
 * already the queue boundary (the scheduler pushes that job, a worker
 * picks it up, and everything from there runs on that worker). This
 * class deliberately does NOT implement `ShouldQueue` itself — queuing it
 * again would just be a second, redundant hop for no benefit, unlike
 * `PostFailedNotification`/`IntegrationBrokenNotification`, which DO
 * implement `ShouldQueue` because THEY are dispatched synchronously
 * from inside request-adjacent code paths as well as jobs, and need
 * their own queue boundary regardless of caller. Every caller of this
 * mailable is already a queued job, so that boundary already exists one
 * layer up.
 *
 * Carries plain scalars and a `DigestSummary` value object, not the
 * `User` model — nothing here is queued/serialized, so there is no
 * `SerializesModels` restoration concern to design around (unlike the
 * notifications above), but passing only what the view needs keeps this
 * class decoupled from `User`'s full shape regardless.
 */
class WeeklyDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $recipientTimezone,
        public readonly DigestSummary $summary,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.weekly_digest.subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.weekly-digest',
            with: [
                'recipientName' => $this->recipientName,
                'recipientTimezone' => $this->recipientTimezone,
                'summary' => $this->summary,
            ],
        );
    }
}
