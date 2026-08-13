<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonInterface;
use Database\Factories\IntegrationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A single connected social-network account/channel for an organization
 * (e.g. one Telegram channel, one Bluesky account).
 *
 * Task 12 flesh-out of the Task-11 placeholder (see git history for that
 * class's original docblock, which explained why it had to exist before
 * this table did). Owns the `integrations` table, tenant scoping via
 * `BelongsToOrganization`, and the encrypted-token casts.
 *
 * Security: `token` and `refresh_token` are ciphered at rest via the
 * `encrypted` cast (backed by `APP_KEY`) and excluded from
 * serialization via `$hidden`, so `toArray()`/`toJson()`/API resources
 * never emit them even by accident. `$hidden` does not protect against
 * a raw `Log::info($integration)` or an unhandled exception dumping the
 * model — callers must not log this model directly; log `$integration->id`
 * instead.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $provider_identifier
 * @property string $internal_id
 * @property string $name
 * @property string|null $picture
 * @property string|null $profile
 * @property string|null $token
 * @property string|null $refresh_token
 * @property CarbonInterface|null $token_expires_at
 * @property array<string, mixed>|null $settings
 * @property string|null $customer_name
 * @property string|null $group_name
 * @property bool $disabled
 * @property bool $in_between_steps
 * @property bool $refresh_needed
 * @property Carbon|null $deleted_at
 */
class Integration extends Model
{
    /** @use HasFactory<IntegrationFactory> */
    use BelongsToOrganization, HasFactory, HasUlids, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'organization_id',
        'provider_identifier',
        'internal_id',
        'name',
        'picture',
        'profile',
        'token',
        'refresh_token',
        'token_expires_at',
        'settings',
        'customer_name',
        'group_name',
        'disabled',
        'in_between_steps',
        'refresh_needed',
    ];

    /**
     * `token`/`refresh_token` are never safe to expose — see the class
     * docblock. This is the layer that protects `toArray()`/`toJson()`
     * and any API resource built on top of this model; it does not
     * protect direct property access (`$integration->token`), which
     * application code still legitimately needs (e.g. to call a
     * provider with it).
     *
     * @var list<string>
     */
    protected $hidden = ['token', 'refresh_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'settings' => 'array',
            'disabled' => 'boolean',
            'in_between_steps' => 'boolean',
            'refresh_needed' => 'boolean',
        ];
    }
}
