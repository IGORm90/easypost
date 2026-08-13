<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\SignatureFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An organization-scoped reusable post signature/footer (Task 23 builds
 * the endpoints; this task lays the schema only).
 *
 * At most one row per organization may have `is_default = true` — see
 * the `signatures_one_default_per_org` partial unique index in the
 * migration, enforced by the database rather than left to application
 * code to keep consistent.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $content
 * @property bool $is_default
 */
class Signature extends Model
{
    /** @use HasFactory<SignatureFactory> */
    use BelongsToOrganization, HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = ['organization_id', 'content', 'is_default'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }
}
