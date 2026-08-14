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
 *
 * Task 34: deliberately no `tags()` method, and no Horizon tags at all —
 * corrected after review, since an earlier version of this task's report
 * claimed Horizon's reflection-based auto-tagging would still pick up
 * the `User` notifiable here, which is wrong. `Laravel\Horizon\Tags::
 * targetsFor()` unwraps a queued `SendQueuedNotifications` job down to
 * `$job->notification` (this class) ALONE before reflecting for `Model`
 * properties — the `$notifiable` (the `User`) lives on the wrapper job,
 * never on this notification instance, and is never reflected over.
 * This class also holds no `Model` property of its own (no constructor
 * args at all). So a `SendQueuedNotifications` job for this notification
 * produces an EMPTY tag array, not an auto-derived `User:` one —
 * verified directly against `Tags::targetsFor()`/`Tags::modelsFor()`,
 * not assumed. Left untagged rather than adding a synthetic one: this
 * is a per-recipient signup email with no organization/group scope to
 * search by, so a tag would not add findability the way
 * `PublishPostJob`'s/`PostFailedNotification`'s do.
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
