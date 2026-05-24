<?php

declare(strict_types=1);

namespace App\Services\Fixtures;

use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class FixtureGenerator
{
    /**
     * Build a double round-robin schedule using the circle method.
     *
     * Each team plays every other team once at home and once away.
     * For n teams, the schedule has n - 1 weeks per leg and 2 legs.
     *
     * @param  list<int>  $teamIds
     * @return list<array{week:int, home_team_id:int, away_team_id:int}>
     */
    public function generate(array $teamIds): array
    {
        $n = count($teamIds);
        if ($n < 2 || $n % 2 !== 0) {
            throw new InvalidArgumentException("Need an even number of teams >= 2; got {$n}.");
        }
        if (count(array_unique($teamIds)) !== $n) {
            throw new InvalidArgumentException('Team ids must be unique.');
        }

        $rotation = array_values($teamIds);
        $rounds = $n - 1;
        $halfN = intdiv($n, 2);
        $firstLeg = [];

        for ($round = 1; $round <= $rounds; $round++) {
            for ($i = 0; $i < $halfN; $i++) {
                $firstLeg[] = [
                    'week' => $round,
                    'home_team_id' => $rotation[$i],
                    'away_team_id' => $rotation[$n - 1 - $i],
                ];
            }

            // Keep $rotation[0] fixed; rotate the rest clockwise by one.
            $tail = array_pop($rotation);
            array_splice($rotation, 1, 0, [$tail]);
        }

        $secondLeg = array_map(
            static fn (array $f) => [
                'week' => $f['week'] + $rounds,
                'home_team_id' => $f['away_team_id'],
                'away_team_id' => $f['home_team_id'],
            ],
            $firstLeg,
        );

        return array_merge($firstLeg, $secondLeg);
    }

    /**
     * Persist a freshly generated schedule for the given season.
     *
     * @param  Collection<int, Team>  $teams
     */
    public function generateForSeason(Season $season, Collection $teams): void
    {
        $teamIds = $teams->pluck('id')->values()->all();
        $now = now();

        $rows = array_map(
            static fn (array $f) => $f + [
                'season_id' => $season->id,
                'played' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $this->generate($teamIds),
        );

        Fixture::insert($rows);
    }
}
