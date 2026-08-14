<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Queued (ShouldQueue) so registration never blocks on SMTP. Copy comes
 * entirely from lang/{locale}/mail.php — the locale is chosen automatically
 * by Illuminate\Notifications\NotificationSender because User implements
 * HasLocalePreference (see App\Models\User::preferredLocale()), so nothing
 * here needs to call App::setLocale() itself.
 */
class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private const EXPIRE_MINUTES = 60;

    public function __construct()
    {
        // Task 34: routes to the `mail` queue — see
        // `PostFailedNotification`'s constructor for why this is
        // assigned here rather than as a property default.
        $this->queue = 'mail';
    }

    /**
     * @return array<int, string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(self::EXPIRE_MINUTES),
            ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())],
        );

        return (new MailMessage)
            ->subject(__('mail.verify_email.subject'))
            ->greeting(__('mail.verify_email.greeting', ['name' => $notifiable->name]))
            ->line(__('mail.verify_email.line'))
            ->action(__('mail.verify_email.action'), $url)
            ->line(__('mail.verify_email.expire', ['count' => self::EXPIRE_MINUTES]))
            ->line(__('mail.verify_email.trouble'));
    }
}
