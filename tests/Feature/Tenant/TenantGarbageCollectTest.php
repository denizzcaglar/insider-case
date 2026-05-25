<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TenantGarbageCollectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_removes_inactive_tenant_seasons_only(): void
    {
        $stale = Season::create([
            'name' => 'stale',
            'is_historical' => false,
            'tenant_id' => 'tenant-old',
            'last_seen_at' => now()->subDays(14),
        ]);

        $active = Season::create([
            'name' => 'active',
            'is_historical' => false,
            'tenant_id' => 'tenant-recent',
            'last_seen_at' => now()->subHours(2),
        ]);

        $templateId = Season::query()
            ->whereNull('tenant_id')
            ->where('is_historical', false)
            ->orderBy('id')
            ->first()?->id;

        $this->artisan('tenant:gc')
            ->assertExitCode(0);

        self::assertNull(Season::find($stale->id), 'stale tenant season should be gone');
        self::assertNotNull(Season::find($active->id), 'recent tenant season should survive');
        self::assertNotNull(Season::find($templateId), 'template season must not be touched');
    }

    public function test_threshold_option_is_respected(): void
    {
        Season::create([
            'name' => 'three-days-old',
            'is_historical' => false,
            'tenant_id' => 'tenant-mid',
            'last_seen_at' => now()->subDays(3),
        ]);

        $this->artisan('tenant:gc', ['--days' => 2])
            ->assertExitCode(0);

        self::assertSame(0, Season::where('tenant_id', 'tenant-mid')->count());
    }
}
