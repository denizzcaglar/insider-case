<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Models\Season;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

trait ResolvesSeason
{
    protected function resolveSeason(Request $request): Season
    {
        $seasonId = $request->integer('season_id');

        if ($seasonId > 0) {
            $season = Season::findOrFail($seasonId);
            $this->ensureSeasonVisibleToCurrentTenant($season);

            return $season;
        }

        return Season::forCurrentTenant();
    }

    private function ensureSeasonVisibleToCurrentTenant(Season $season): void
    {
        if ($season->is_historical) {
            return;
        }

        $tenant = app(TenantContext::class);
        if (! $tenant->has()) {
            return;
        }

        if ($season->tenant_id !== $tenant->tenantId) {
            throw new AccessDeniedHttpException('Season does not belong to this visitor.');
        }
    }
}
