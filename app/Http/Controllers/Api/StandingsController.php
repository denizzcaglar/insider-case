<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesSeason;
use App\Http\Controllers\Controller;
use App\Http\Presenters\SeasonStatePresenter;
use App\Http\Presenters\StandingsPresenter;
use App\Services\League\LeagueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StandingsController extends Controller
{
    use ResolvesSeason;

    public function __construct(private readonly LeagueService $league)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $season = $this->resolveSeason($request);

        return new JsonResponse([
            'season' => SeasonStatePresenter::present($season, $this->league),
            'standings' => StandingsPresenter::present($this->league->standingsFor($season)),
        ]);
    }
}
