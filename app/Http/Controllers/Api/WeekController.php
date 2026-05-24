<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesSeason;
use App\Http\Controllers\Controller;
use App\Http\Presenters\SeasonStatePresenter;
use App\Http\Presenters\StandingsPresenter;
use App\Http\Resources\FixtureResource;
use App\Services\League\LeagueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class WeekController extends Controller
{
    use ResolvesSeason;

    public function __construct(private readonly LeagueService $league)
    {
    }

    public function next(Request $request): JsonResponse
    {
        $season = $this->resolveSeason($request);

        try {
            $played = $this->league->nextWeek($season);
        } catch (RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 409);
        }

        $week = (int) $played->first()->week;

        return new JsonResponse([
            'season' => SeasonStatePresenter::present($season->fresh(), $this->league),
            'week_played' => $week,
            'results' => FixtureResource::collection($played)->resolve(),
            'standings' => StandingsPresenter::present($this->league->standingsFor($season)),
        ]);
    }

    public function playAll(Request $request): JsonResponse
    {
        $season = $this->resolveSeason($request);
        $byWeek = $this->league->playAll($season);

        $weeks = [];
        foreach ($byWeek as $week => $fixtures) {
            $weeks[] = [
                'week' => $week,
                'results' => FixtureResource::collection($fixtures)->resolve(),
            ];
        }

        return new JsonResponse([
            'season' => SeasonStatePresenter::present($season->fresh(), $this->league),
            'weeks_played' => $weeks,
            'final_standings' => StandingsPresenter::present($this->league->standingsFor($season)),
        ]);
    }
}
