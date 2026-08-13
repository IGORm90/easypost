<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;

// See LoginTest.php for why this Referer header is required: Sanctum's
// EnsureFrontendRequestsAreStateful only starts a session for requests it
// recognises as coming from the SPA.
beforeEach(function () {
    $this->withHeader('Referer', config('app.url'));
});

it('queues a verification notification when a user registers', function () {
    Notification::fake();

    $this->postJson('/api/auth/register', [
        'name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'secret1234',
    ])->assertCreated();

    $user = User::firstWhere('email', 'ann@example.com');
    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

it('sends the verification notification even when email_notifications is disabled', function () {
    // Verification mail is transactional/security mail, not marketing — it
    // must still go out regardless of the user's notification preference.
    Notification::fake();

    $this->postJson('/api/auth/register', [
        'name' => 'Ann', 'email' => 'ann@example.com', 'password' => 'secret1234',
    ])->assertCreated();

    $user = User::firstWhere('email', 'ann@example.com');
    $user->forceFill(['email_notifications' => false])->save();

    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

it('renders the verification email copy from the translation files, not hardcoded', function () {
    $ru = User::factory()->unverified()->create(['locale' => 'ru']);
    $en = User::factory()->unverified()->create(['locale' => 'en']);

    app()->setLocale('ru');
    $ruSubject = (new VerifyEmailNotification)->toMail($ru)->subject;
    app()->setLocale('en');
    $enSubject = (new VerifyEmailNotification)->toMail($en)->subject;

    expect($ruSubject)->toBe(trans('mail.verify_email.subject', [], 'ru'))
        ->and($enSubject)->toBe(trans('mail.verify_email.subject', [], 'en'))
        ->and($ruSubject)->not->toBe($enSubject);
});

it('automatically renders in the recipient locale when actually sent, regardless of the current app locale', function () {
    // Proves the HasLocalePreference wiring (App\Models\User::preferredLocale())
    // actually drives Illuminate\Notifications\NotificationSender's locale
    // switch, rather than relying on a caller to set the locale manually.
    // Notification::fake()/Mail::fake() can't observe this: MailChannel
    // sends via raw view+data (not a Mailable), which Mail::fake() no-ops
    // on, and Notification::fake() never calls toMail() at all. The real
    // 'array' transport (MAIL_MAILER=array, phpunit.xml) does actually
    // render and record the message, so this reads the rendered subject
    // straight off it.
    $user = User::factory()->unverified()->create(['locale' => 'ru']);

    app()->setLocale('en');
    $user->notify(new VerifyEmailNotification);

    $transport = app('mailer')->getSymfonyTransport();
    assert($transport instanceof ArrayTransport);
    $messages = $transport->messages();

    expect($messages)->toHaveCount(1);
    $sentMessage = $messages->first()->getOriginalMessage();
    assert($sentMessage instanceof Email);
    expect($sentMessage->getSubject())->toBe(trans('mail.verify_email.subject', [], 'ru'));
});

it('verifies the email address via a valid signed link', function () {
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
    );

    $this->getJson($url)->assertOk();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('is idempotent: verifying an already-verified email succeeds without error', function () {
    $user = User::factory()->create(); // factory default: already verified

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
    );

    $this->getJson($url)->assertOk();
    $this->getJson($url)->assertOk();

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('rejects an expired signed verification link', function () {
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->subMinute(),
        ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
    );

    $this->getJson($url)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('rejects a link whose hash does not match the user email', function () {
    $user = User::factory()->unverified()->create();

    // A syntactically valid signature (we control the signing key via
    // URL::temporarySignedRoute itself) but a hash that doesn't correspond
    // to this user's current email — simulates the email having changed
    // after the link was issued.
    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->getKey(), 'hash' => sha1('someone-else@example.com')],
    );

    $this->getJson($url)->assertForbidden();
    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('rejects a link generated for a different user id (tampered signature)', function () {
    $userA = User::factory()->unverified()->create();
    $userB = User::factory()->unverified()->create();

    $urlForA = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $userA->getKey(), 'hash' => sha1($userA->getEmailForVerification())],
    );

    // Swap in user B's id — this invalidates the signature, since the
    // signature covers every route parameter, not just the hash.
    $urlForB = str_replace($userA->getKey(), $userB->getKey(), $urlForA);

    $this->getJson($urlForB)->assertForbidden();
    expect($userB->fresh()->hasVerifiedEmail())->toBeFalse();
});

it('resends the verification mail to the authenticated user', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['password' => Hash::make('secret1234')]);

    $login = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret1234'])->assertOk();
    $cookieName = config('session.cookie');

    $this->withCookie($cookieName, $login->getCookie($cookieName)->getValue())
        ->postJson('/api/auth/email/resend')
        ->assertOk();

    Notification::assertSentTo($user, VerifyEmailNotification::class);
});

it('rate limits repeated resend requests', function () {
    $user = User::factory()->unverified()->create(['password' => Hash::make('secret1234')]);
    $login = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'secret1234'])->assertOk();
    $cookieName = config('session.cookie');
    $client = $this->withCookie($cookieName, $login->getCookie($cookieName)->getValue());

    for ($i = 0; $i < 3; $i++) {
        $client->postJson('/api/auth/email/resend')->assertOk();
    }

    $client->postJson('/api/auth/email/resend')->assertStatus(429);
});

it('rejects the resend endpoint for an unauthenticated caller', function () {
    $this->postJson('/api/auth/email/resend')->assertUnauthorized();
});
