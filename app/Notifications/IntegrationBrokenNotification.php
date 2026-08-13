<?php

namespace App\Notifications;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to an organization's owner when Task 16's TokenRefreshService
 * cannot refresh a channel's OAuth token because the provider rejected
 * the credential outright (ProviderAuthException — the refresh token
 * itself is dead, not a transient hiccup). TokenRefreshService::refresh()
 * sends this at most once per "the channel went from working to broken"
 * transition; see its markBroken() for the anti-spam guard.
 *
 * Carries the Integration model, not a bare id/name pair, for the same
 * reason RefreshIntegrationTokenJob does: SerializesModels restores a
 * queued model via Model::newQueryForRestoration(), which explicitly
 * bypasses ALL global scopes (including BelongsToOrganization's), so
 * this is safe to queue and rehydrate with no OrganizationContext set on
 * the worker — unlike a query built with the model's normal, scoped
 * query builder. Only $integration->name (display data the owner
 * already sees in the product) is ever read in toMail() — never
 * ->token/->refresh_token, which stay $hidden and are never logged or
 * rendered here.
 */
class IntegrationBrokenNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Integration $integration,
    ) {}

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
            ->subject(__('mail.integration_broken.subject'))
            ->greeting(__('mail.integration_broken.greeting', ['name' => $notifiable->name]))
            ->line(__('mail.integration_broken.line', ['channel' => $this->integration->name]))
            ->line(__('mail.integration_broken.action_line'))
            ->line(__('mail.integration_broken.trouble'));
    }
}
