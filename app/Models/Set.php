<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\SetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An organization-scoped, reusable bundle of per-channel post content
 * (Task 23 builds the endpoints; this task lays the schema only).
 *
 * `$table` is set explicitly rather than left to Eloquent's
 * pluralisation-of-the-class-name convention: "Set" is a common enough
 * word elsewhere (including as an SQL keyword) that relying on the
 * implicit `sets` inference would be one accidental class rename away
 * from silently pointing at the wrong table.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property array<string, mixed> $content
 */
class Set extends Model
{
    /** @use HasFactory<SetFactory> */
    use BelongsToOrganization, HasFactory, HasUlids;

    protected $table = 'sets';

    /** @var list<string> */
    protected $fillable = ['organization_id', 'name', 'content'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content' => 'array',
        ];
    }
}
