<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property CarbonInterface|null $digest_sent_at Task 33's digest claim —
 *                                                see DigestService::claim()'s docblock. Only this property gets an
 *                                                explicit annotation (rather than documenting the whole model, which
 *                                                predates this task): Larastan infers plain scalar-cast columns
 *                                                (timezone, locale, email_notifications) fine from casts() alone, but
 *                                                needs a `datetime`-cast column's real return type (CarbonInterface,
 *                                                not the raw column string) spelled out explicitly to type-check
 *                                                `$row->digest_sent_at->isAfter(...)` — the same reason PostGroup's
 *                                                class docblock already annotates its own `notified_at`/`publish_date`.
 */
#[Fillable(['name', 'email', 'password', 'timezone', 'locale', 'email_notifications'])]
#[Hidden(['password', 'remember_token', 'api_key'])]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlids, Notifiable;

    /**
     * Mirrors the `users` migration's DB-level column defaults
     * (`timezone` default 'UTC', `locale` default 'en',
     * `email_notifications` default true) so a freshly instantiated or
     * freshly created in-memory model already carries those values,
     * without needing a round trip back to the database.
     *
     * This matters beyond convenience: `timezone` is what later scheduling
     * code (Task 21's slot finder) uses to decide when a post publishes. A
     * null timezone reaching that code silently computes a wrong publish
     * time rather than throwing — the DB default alone doesn't protect
     * against that, because nothing forces a caller to refresh() the model
     * after User::create() before reading it (AuthController::register()
     * doesn't, and there is no reason a future caller would know to).
     * `email_notifications` is included for the same reason: since Task 10,
     * UserResource exposes it, and AuthController::register() returns the
     * in-memory model straight back to the caller, unrefreshed.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'timezone' => 'UTC',
        'locale' => 'en',
        'email_notifications' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_superadmin' => 'boolean',
            'email_notifications' => 'boolean',
            'digest_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Organization, $this, OrganizationUser>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_user')
            ->using(OrganizationUser::class)
            ->withPivot('id', 'role')
            ->withTimestamps();
    }

    /**
     * Overrides the default (Illuminate\Auth\MustVerifyEmail trait, already
     * pulled in by the base Authenticatable) to send our own translated,
     * queued notification instead of the framework's untranslated one.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /**
     * Overrides the default (Illuminate\Auth\Passwords\CanResetPassword
     * trait, already pulled in by the base Authenticatable) for the same
     * reason as sendEmailVerificationNotification() above.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /**
     * Read by Illuminate\Notifications\NotificationSender to pick the
     * locale mail notifications render in, so VerifyEmailNotification and
     * ResetPasswordNotification never need to call App::setLocale()
     * themselves.
     */
    public function preferredLocale(): string
    {
        // No longer needs a `?? config('app.locale')` fallback: the
        // $attributes default above guarantees `locale` is never null on
        // an in-memory instance, so this is a straight read.
        return $this->locale;
    }
}
