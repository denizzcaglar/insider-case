<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Models\Season;
use Illuminate\Http\Request;

/**
 * Shared "resolve which season this request targets" helper for API controllers.
 *
 * Accepts an explicit `?season_id=` query parameter (or `season_id` form input).
 * Falls back to {@see Season::current()} so existing single-season usage keeps working.
 */
trait ResolvesSeason
{
    protected function resolveSeason(Request $request): Season
    {
        $seasonId = $request->integer('season_id');

        if ($seasonId > 0) {
            return Season::findOrFail($seasonId);
        }

        return Season::current();
    }
}
