<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Fixture;
use App\Models\Season;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks state-changing requests targeting a historical (real-data) season.
 *
 * Resolves the target season the same way controllers do (via `?season_id=` or
 * the default), with one special case: when the route binds a {fixture}, the
 * season is read off the fixture itself.
 */
final class RejectHistoricalSeasonMutation
{
    public function handle(Request $request, Closure $next): Response
    {
        $season = $this->resolveSeason($request);

        if ($season !== null && $season->is_historical) {
            return new JsonResponse(
                [
                    'message' => 'Historical seasons are read-only.',
                    'season' => [
                        'id' => (int) $season->id,
                        'name' => $season->name,
                    ],
                ],
                422,
            );
        }

        return $next($request);
    }

    private function resolveSeason(Request $request): ?Season
    {
        // Route-bound fixture takes precedence (PATCH /api/fixtures/{fixture}).
        $fixture = $request->route('fixture');
        if ($fixture instanceof Fixture) {
            return $fixture->season;
        }

        $seasonId = $request->integer('season_id');
        if ($seasonId > 0) {
            return Season::find($seasonId);
        }

        return Season::query()->where('is_historical', false)->orderBy('id')->first();
    }
}
