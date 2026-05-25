<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesSeason;
use App\Http\Controllers\Controller;
use App\Http\Presenters\SeasonStatePresenter;
use App\Http\Presenters\StandingsPresenter;
use App\Http\Requests\UpdateFixtureRequest;
use App\Http\Resources\FixtureResource;
use App\Http\Resources\MatchEventResource;
use App\Models\Fixture;
use App\Services\League\LeagueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FixturesController extends Controller
{
    use ResolvesSeason;

    public function __construct(private readonly LeagueService $league)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $season = $this->resolveSeason($request);

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->with(['homeTeam', 'awayTeam'])
            ->withCount('events')
            ->orderBy('week')
            ->orderBy('id')
            ->get()
            ->groupBy('week')
            ->map(fn ($group) => FixtureResource::collection($group)->resolve());

        return new JsonResponse([
            'season' => SeasonStatePresenter::present($season, $this->league),
            'fixtures_by_week' => $fixtures,
        ]);
    }

    public function events(Fixture $fixture): JsonResponse
    {
        $fixture->loadMissing(['homeTeam', 'awayTeam']);
        $events = $fixture->events()->with('player:id,name')->get();

        if ($events->isEmpty()) {
            return new JsonResponse([
                'message' => 'No events recorded for this fixture.',
            ], 404);
        }

        return new JsonResponse([
            'fixture_id' => (int) $fixture->id,
            'score' => [
                'home' => (int) $fixture->home_goals,
                'away' => (int) $fixture->away_goals,
            ],
            'events' => MatchEventResource::collection($events)->resolve(),
        ]);
    }

    public function update(UpdateFixtureRequest $request, Fixture $fixture): JsonResponse
    {
        $season = $fixture->season;
        $fixture = $this->league->editResult($fixture, $request->homeGoals(), $request->awayGoals());

        $fixture->load(['homeTeam', 'awayTeam']);

        return new JsonResponse([
            'season' => SeasonStatePresenter::present($season, $this->league),
            'fixture' => (new FixtureResource($fixture))->toArray(request()),
            'standings' => StandingsPresenter::present($this->league->standingsFor($season)),
        ]);
    }
}
