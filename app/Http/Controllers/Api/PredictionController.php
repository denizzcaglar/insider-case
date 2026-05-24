<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Contracts\ChampionshipPredictor;
use App\Domain\ValueObjects\StrengthBreakdown;
use App\Http\Concerns\ResolvesSeason;
use App\Http\Controllers\Controller;
use App\Http\Presenters\SeasonStatePresenter;
use App\Http\Requests\PredictionQueryRequest;
use App\Models\Team;
use App\Services\League\LeagueService;
use Illuminate\Http\JsonResponse;

final class PredictionController extends Controller
{
    use ResolvesSeason;

    public function __construct(
        private readonly LeagueService $league,
        private readonly ChampionshipPredictor $predictor,
    ) {
    }

    public function index(PredictionQueryRequest $request): JsonResponse
    {
        $season = $this->resolveSeason($request);
        $result = $this->predictor->predict(
            season: $season,
            iterations: $request->iterations(),
            seed: $request->seed(),
        );

        $teamsById = Team::orderBy('id')->get()->keyBy('id');
        $predictions = [];
        foreach ($result->titleProbabilities as $teamId => $probability) {
            $team = $teamsById[$teamId];
            $predictions[] = [
                'team' => [
                    'id' => $team->id,
                    'name' => $team->name,
                    'short_name' => $team->short_name,
                ],
                'title_probability' => round($probability, 2),
            ];
        }

        usort($predictions, fn ($a, $b) => $b['title_probability'] <=> $a['title_probability']);

        $modelInputs = array_map(
            static fn (StrengthBreakdown $b) => [
                'team' => [
                    'id' => $b->teamId,
                    'name' => $b->name,
                    'short_name' => $b->shortName,
                ],
                'seed' => [
                    'attack' => round($b->seedAttack, 2),
                    'defense' => round($b->seedDefense, 2),
                ],
                'prior' => [
                    'attack' => round($b->priorAttack, 2),
                    'defense' => round($b->priorDefense, 2),
                ],
                'form' => [
                    'attack' => round($b->formAttack, 3),
                    'defense' => round($b->formDefense, 3),
                ],
                'effective' => [
                    'attack' => round($b->effectiveAttack, 2),
                    'defense' => round($b->effectiveDefense, 2),
                ],
            ],
            array_values($result->modelInputs),
        );

        return new JsonResponse([
            'season' => SeasonStatePresenter::present($season, $this->league),
            'iterations' => $result->iterations,
            'seed' => $result->seed,
            'predictions' => $predictions,
            'model_inputs' => $modelInputs,
        ]);
    }
}
