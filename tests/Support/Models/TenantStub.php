<?php

namespace Tests\Support\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only model used to exercise BelongsToOrganization in isolation.
 *
 * Task 4 tests the tenant-scoping trait against this stub rather than a real
 * domain model: `Organization` (Task 5) and `Tag` (Task 18) do not exist yet,
 * and what's under test here is the trait's behaviour, not any particular
 * tenant-owned model. Backed by a table created/dropped inside the covering
 * test (see tests/Unit/BelongsToOrganizationTest.php).
 *
 * @property string $id
 * @property string|null $organization_id
 * @property string $name
 */
class TenantStub extends Model
{
    use BelongsToOrganization, HasUlids;

    protected $table = 'tenant_stubs';

    /** @var list<string> */
    protected $fillable = ['name', 'organization_id'];
}
