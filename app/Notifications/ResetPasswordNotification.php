<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Queued (ShouldQueue), locale resolved automatically the same way as
 * VerifyEmailNotification — see that class's docblock.
 *
 * The reset link points at the SPA's catch-all route (routes/web.php),
 * carrying {token, email} as query params: there is no backend page here to
 * link to (Task 10 owns the reset-password Vue page), and this is the same
 * convention Laravel Breeze/Fortify use for a decoupled SPA frontend.
 *
 * Task 34: deliberately no `tags()` method, and no Horizon tags at all —
 * same reasoning as `VerifyEmailNotification`'s docblock (see that
 * class): Horizon's auto-tagging reflects over `$job->notification`
 * only, never the wrapper's `$notifiable`, and `$token` here is a plain
 * string, not a `Model` — so this produces an empty tag array, not an
 * auto-derived one. Left untagged: a password-reset email has no
 * organization/group scope to search a failure by.
 */
class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $token)
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
        $url = url('/reset-password?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]));

        return (new MailMessage)
            ->subject(__('mail.reset_password.subject'))
            ->greeting(__('mail.reset_password.greeting', ['name' => $notifiable->name]))
            ->line(__('mail.reset_password.line'))
            ->action(__('mail.reset_password.action'), $url)
            ->line(__('mail.reset_password.expire', ['count' => (int) config('auth.passwords.users.expire')]))
            ->line(__('mail.reset_password.trouble'));
    }
}
