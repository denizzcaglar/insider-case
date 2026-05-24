<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Season;
use Illuminate\Http\JsonResponse;

/**
 * Lists every season alongside enough metadata to populate a season picker.
 *
 * No mutations — creating new seasons is intentionally not exposed yet; reviewers
 * use the existing seed (and the second demo season the seeder creates) to switch.
 */
final class SeasonsController extends Controller
{
    public function index(): JsonResponse
    {
        $current = Season::current();

        $seasons = Season::query()
            ->orderBy('id')
            ->withCount([
                'fixtures',
                'fixtures as fixtures_played_count' => fn ($q) => $q->where('played', true),
            ])
            ->get()
            ->map(fn (Season $s) => [
                'id' => (int) $s->id,
                'name' => $s->name,
                'rng_seed' => $s->rng_seed,
                'is_historical' => (bool) $s->is_historical,
                'fixtures_total' => (int) $s->fixtures_count,
                'fixtures_played' => (int) $s->fixtures_played_count,
                'is_current' => $s->id === $current->id,
            ])
            ->all();

        return new JsonResponse([
            'seasons' => $seasons,
            'current_season_id' => $current->id,
        ]);
    }
}
