<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesSeason;
use App\Http\Controllers\Controller;
use App\Models\PredictionSnapshot;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Serves the week-by-week prediction history used by the front-end chart.
 *
 * Shape (one entry per team, ordered by team id):
 *
 *   {
 *     "season_id": 1,
 *     "weeks": [1, 2, 3, ...],
 *     "series": [
 *       {"team": {"id": 1, "name": "...", "short_name": "MCI"}, "probabilities": [25.4, 28.0, 31.5, ...]},
 *       ...
 *     ]
 *   }
 *
 * `probabilities[i]` corresponds to `weeks[i]`. Missing weeks are filled with
 * null so the front-end can decide how to draw the gap.
 */
final class PredictionSnapshotController extends Controller
{
    use ResolvesSeason;

    public function index(Request $request): JsonResponse
    {
        $season = $this->resolveSeason($request);

        $snapshots = PredictionSnapshot::query()
            ->where('season_id', $season->id)
            ->orderBy('week_number')
            ->orderBy('team_id')
            ->get(['week_number', 'team_id', 'probability']);

        $weeks = $snapshots->pluck('week_number')->unique()->sort()->values()->all();
        $teams = Team::orderBy('id')->get(['id', 'name', 'short_name']);

        // index[team_id][week_number] = probability
        $index = [];
        foreach ($snapshots as $row) {
            $index[(int) $row->team_id][(int) $row->week_number] = (float) $row->probability;
        }

        $series = $teams->map(fn (Team $t) => [
            'team' => [
                'id' => (int) $t->id,
                'name' => $t->name,
                'short_name' => $t->short_name,
            ],
            'probabilities' => array_map(
                static fn (int $w) => $index[(int) $t->id][$w] ?? null,
                $weeks,
            ),
        ])->all();

        return new JsonResponse([
            'season_id' => (int) $season->id,
            'weeks' => $weeks,
            'series' => $series,
        ]);
    }
}
