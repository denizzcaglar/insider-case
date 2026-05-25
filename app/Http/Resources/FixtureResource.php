<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Fixture;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Fixture $resource
 */
final class FixtureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Fixture $f */
        $f = $this->resource;

        return [
            'id' => $f->id,
            'week' => $f->week,
            'played' => (bool) $f->played,
            'home_team' => [
                'id' => $f->home_team_id,
                'name' => $f->relationLoaded('homeTeam') ? $f->homeTeam->name : null,
                'short_name' => $f->relationLoaded('homeTeam') ? $f->homeTeam->short_name : null,
            ],
            'away_team' => [
                'id' => $f->away_team_id,
                'name' => $f->relationLoaded('awayTeam') ? $f->awayTeam->name : null,
                'short_name' => $f->relationLoaded('awayTeam') ? $f->awayTeam->short_name : null,
            ],
            'home_goals' => $f->home_goals,
            'away_goals' => $f->away_goals,
            'simulated_at' => $f->simulated_at?->toIso8601String(),
            'events_count' => (int) ($f->events_count ?? 0),
        ];
    }
}
