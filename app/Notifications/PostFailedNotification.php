<?php

namespace App\Notifications;

use App\Models\PostGroup;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to a post group's author (`PostGroup::creator()`) once its group
 * has settled into `ERROR` with nothing left pending — Task 32's
 * "report by errors" half. Only ever dispatched by
 * `MissedPostsService::notifyFailed()`, which guards this to at most one
 * send per group via `post_groups.notified_at` (see that method's
 * docblock) — this class itself carries no anti-spam logic of its own,
 * same division of responsibility as `IntegrationBrokenNotification`/
 * `TokenRefreshService::markBroken()`.
 *
 * Carries the `PostGroup` model, not a bare id: `SerializesModels`
 * restores a queued model via `Model::newQueryForRestoration()`, which
 * bypasses every global scope (including `BelongsToOrganization`'s) —
 * safe to queue and rehydrate with no `OrganizationContext` set on the
 * worker, exactly like `IntegrationBrokenNotification`'s docblock
 * explains for `Integration`.
 *
 * `$this->group->error` is the only failure detail ever rendered here —
 * see `MissedPostsService::buildErrorSummary()` for why that string is
 * always a short, customer-facing sentence naming the affected
 * channel(s), never a raw exception message or stack trace.
 */
class PostFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PostGroup $group,
    ) {
        // Task 34: routes to the `mail` queue (two dedicated workers —
        // see config/horizon.php) without touching any dispatch call
        // site. Assigned here, not as a property default: `Queueable`
        // (used above) already declares `$queue` untyped with no
        // default, and PHP treats a class-level redeclaration with a
        // *different* default as an incompatible trait composition — a
        // hard fatal at class-load time (verified directly against
        // `PublishPostJob`'s identical case; see that class's
        // constructor for the full explanation).
        $this->queue = 'mail';
    }

    /**
     * Task 34: Horizon tags. `$group` is already an in-memory model (see
     * the class docblock), so this costs no extra query. Ids only — see
     * `PublishPostJob::tags()`'s docblock for why.
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
            ->subject(__('mail.post_failed.subject'))
            ->greeting(__('mail.post_failed.greeting', ['name' => $notifiable->name]))
            ->line(__('mail.post_failed.line', [
                'date' => $this->group->publish_date?->toDayDateTimeString() ?? __('mail.post_failed.unknown_date'),
            ]))
            ->line((string) $this->group->error)
            ->line(__('mail.post_failed.action_line'));
    }
}
