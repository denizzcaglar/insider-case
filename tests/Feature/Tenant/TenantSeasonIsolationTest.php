<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Models\Season;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TenantSeasonIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function bindTenant(?string $tenantId): void
    {
        $this->app->instance(TenantContext::class, new TenantContext($tenantId));
    }

    public function test_no_tenant_context_returns_template_season(): void
    {
        $this->bindTenant(null);

        $season = Season::forCurrentTenant();

        self::assertNull($season->tenant_id);
        self::assertFalse((bool) $season->is_historical);
    }

    public function test_first_visit_clones_template_into_tenant_specific_season(): void
    {
        $this->bindTenant('tenant-A');

        $season = Season::forCurrentTenant();

        self::assertSame('tenant-A', $season->tenant_id);
        self::assertFalse((bool) $season->is_historical);
        self::assertNotNull($season->last_seen_at);
    }

    public function test_two_tenants_get_distinct_seasons(): void
    {
        $this->bindTenant('tenant-A');
        $seasonA = Season::forCurrentTenant();

        $this->bindTenant('tenant-B');
        $seasonB = Season::forCurrentTenant();

        self::assertNotSame($seasonA->id, $seasonB->id);
        self::assertSame('tenant-A', $seasonA->tenant_id);
        self::assertSame('tenant-B', $seasonB->tenant_id);
    }

    public function test_returning_tenant_reuses_the_same_season(): void
    {
        $this->bindTenant('tenant-A');
        $first = Season::forCurrentTenant();

        $this->bindTenant('tenant-A');
        $second = Season::forCurrentTenant();

        self::assertSame($first->id, $second->id);
    }

    public function test_visible_scope_returns_own_simulated_and_all_historical(): void
    {
        $this->bindTenant('tenant-A');
        Season::forCurrentTenant();

        $this->bindTenant('tenant-B');
        Season::forCurrentTenant();

        $this->bindTenant('tenant-A');
        $visible = Season::query()->visibleToCurrentTenant()->get();

        foreach ($visible as $season) {
            self::assertTrue(
                $season->is_historical || $season->tenant_id === 'tenant-A',
                "Season {$season->id} should be visible to tenant-A",
            );
        }

        $hasOwnSimulated = $visible->contains(
            fn (Season $s) => ! $s->is_historical && $s->tenant_id === 'tenant-A',
        );
        $hasOtherSimulated = $visible->contains(
            fn (Season $s) => ! $s->is_historical && $s->tenant_id === 'tenant-B',
        );
        $hasHistorical = $visible->contains(fn (Season $s) => $s->is_historical);

        self::assertTrue($hasOwnSimulated, 'own simulated season should be visible');
        self::assertFalse($hasOtherSimulated, 'other tenant season must be hidden');
        self::assertTrue($hasHistorical, 'historical seasons should remain visible');
    }
}
