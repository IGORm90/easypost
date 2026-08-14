<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Links one local User to one OAuth-provider identity
 * (`provider` + `provider_user_id`, unique together — see the migration).
 * Never exposed through a JsonResource; `access_token`/`refresh_token` are
 * additionally `#[Hidden]` and `encrypted`-cast as defence in depth, so a
 * future `toArray()`/`toJson()` call on this model still can't leak them.
 *
 * @property string $id
 * @property string $user_id
 * @property string $provider
 * @property string $provider_user_id
 * @property string|null $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $token_expires_at
 */
#[Fillable(['user_id', 'provider', 'provider_user_id', 'access_token', 'refresh_token', 'token_expires_at'])]
#[Hidden(['access_token', 'refresh_token'])]
class SocialAccount extends Model
{
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
