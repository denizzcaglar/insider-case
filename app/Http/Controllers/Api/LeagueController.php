<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesSeason;
use App\Http\Controllers\Controller;
use App\Http\Presenters\SeasonStatePresenter;
use App\Http\Presenters\StandingsPresenter;
use App\Http\Requests\ResetLeagueRequest;
use App\Http\Resources\FixtureResource;
use App\Models\Fixture;
use App\Services\League\LeagueService;
use Illuminate\Http\JsonResponse;

final class LeagueController extends Controller
{
    use ResolvesSeason;

    public function __construct(private readonly LeagueService $league)
    {
    }

    public function reset(ResetLeagueRequest $request): JsonResponse
    {
        $season = $this->resolveSeason($request);
        $this->league->reset($season, $request->seed());

        $fixturesByWeek = Fixture::query()
            ->where('season_id', $season->id)
            ->with(['homeTeam', 'awayTeam'])
            ->orderBy('week')
            ->orderBy('id')
            ->get()
            ->groupBy('week')
            ->map(fn ($group) => FixtureResource::collection($group)->resolve());

        return new JsonResponse([
            'season' => SeasonStatePresenter::present($season->fresh(), $this->league),
            'fixtures_by_week' => $fixturesByWeek,
            'standings' => StandingsPresenter::present($this->league->standingsFor($season)),
        ]);
    }
}
