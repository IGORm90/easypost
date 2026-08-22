<?php

namespace App\Notifications;

use App\Models\PostGroup;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Block 4: sent to a recurring post's author when the series could not
 * be continued because the organization has used up its monthly post
 * quota (`PlanLimit::POSTS_PER_MONTH`).
 *
 * Sent from `RecurrenceService::pause()` — the only dispatcher — which
 * has already taken the `repeat_spawned_at` claim, so this goes out at
 * most once per occurrence however many times `PublishPostJob` is
 * redelivered. Same division of responsibility as
 * `PostFailedNotification`/`MissedPostsService::notifyFailed()`: the
 * anti-spam guard is the caller's, not this class's.
 *
 * The alternative — stopping the series silently — is the failure mode
 * this exists to prevent: a weekly post that quietly stopped is noticed
 * a month later, and by then nothing on the screen explains why.
 *
 * Carries the `PostGroup` model rather than an id: `SerializesModels`
 * restores a queued model through `Model::newQueryForRestoration()`,
 * which bypasses every global scope, so this rehydrates correctly on a
 * worker with no `OrganizationContext` — see `PostFailedNotification`'s
 * docblock for the same reasoning.
 */
class RecurrencePausedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PostGroup $group,
    ) {
        // Routes to the `mail` queue without touching the dispatch site.
        // Assigned in the constructor, not as a property default:
        // `Queueable` already declares `$queue` untyped with no default,
        // and a class-level redeclaration with a different default is an
        // incompatible trait composition — a hard fatal at class-load
        // time. See PublishPostJob's constructor for the full account.
        $this->queue = 'mail';
    }

    /**
     * Horizon tags. Ids only, never names or addresses — tags render in
     * a web UI (see `PublishPostJob::tags()`).
     *
     * @return list<string>
     */
    public function tags(): array
    {
        return [
            "organization:{$this->group->organization_id}",
            "group:{$this->group->id}",
        ];
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
        return (new MailMessage)
            ->subject(__('mail.recurrence_paused.subject'))
            ->greeting(__('mail.recurrence_paused.greeting', ['name' => $notifiable->name]))
            ->line(__('mail.recurrence_paused.line', [
                'date' => $this->group->publish_date?->toDayDateTimeString() ?? __('mail.recurrence_paused.unknown_date'),
            ]))
            ->line(__('mail.recurrence_paused.reason'))
            ->line(__('mail.recurrence_paused.action_line'));
    }
}
