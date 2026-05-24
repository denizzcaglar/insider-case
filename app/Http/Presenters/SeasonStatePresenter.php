<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Models\Fixture;
use App\Models\Season;
use App\Services\League\LeagueService;

final class SeasonStatePresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Season $season, LeagueService $league): array
    {
        $currentWeek = $league->currentWeek($season);

        $totalFixtures = Fixture::query()->where('season_id', $season->id)->count();
        $playedFixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->where('played', true)
            ->count();

        return [
            'id' => $season->id,
            'name' => $season->name,
            'is_historical' => (bool) $season->is_historical,
            'current_week' => $currentWeek,
            'is_complete' => $totalFixtures > 0 && $currentWeek === null,
            'fixtures_total' => $totalFixtures,
            'fixtures_played' => $playedFixtures,
            'rng_seed' => $season->rng_seed,
        ];
    }
}
