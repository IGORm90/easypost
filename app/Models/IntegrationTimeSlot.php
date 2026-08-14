<?php

namespace App\Models;

use Database\Factories\IntegrationTimeSlotFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One weekly recurring posting slot for a connected channel. Storage-only
 * (see the Task-12 migration's docblock) until a later task's scheduler
 * reads it; Task 13 is the first thing that writes it, via
 * `IntegrationService::update()`.
 *
 * Deliberately does NOT use `BelongsToOrganization`: this table has no
 * `organization_id` column. Tenant scoping is inherited transitively —
 * every row is only ever reached through `Integration::timeSlots()`,
 * and `Integration` itself is organization-scoped, so a row can never be
 * read or written except through an already tenant-checked parent.
 *
 * @property string $id
 * @property string $integration_id
 * @property int $minutes_from_week_start
 */
class IntegrationTimeSlot extends Model
{
    /** @use HasFactory<IntegrationTimeSlotFactory> */
    use HasFactory, HasUlids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['integration_id', 'minutes_from_week_start'];

    /**
     * @return BelongsTo<Integration, $this>
     */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
