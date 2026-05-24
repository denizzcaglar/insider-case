<?php

declare(strict_types=1);

namespace App\Services\Standings;

use App\Domain\ValueObjects\PlayedFixture;
use App\Domain\ValueObjects\StandingRow;
use App\Domain\ValueObjects\StandingsTable;
use App\Domain\ValueObjects\TeamStrength;

/**
 * Builds a {@see StandingsTable} from a set of teams and the matches they have played.
 *
 * Premier League tiebreakers are applied as an ordered list of comparator callables.
 * Head-to-head rules are delegated to a {@see HeadToHeadResolver} because head-to-head
 * is a pair-wise relationship between two teams, not a property of a single team —
 * computing it via per-row counters would be a leaky abstraction.
 */
final class StandingsCalculator
{
    /**
     * @param  iterable<TeamStrength>  $teams
     * @param  iterable<PlayedFixture>  $playedFixtures
     */
    public function calculate(
        iterable $teams,
        iterable $playedFixtures,
        ?callable $comparator = null,
    ): StandingsTable {
        $rows = [];
        foreach ($teams as $team) {
            $rows[] = new StandingRow(
                teamId: $team->id,
                teamName: $team->name,
                teamShortName: $team->shortName,
            );
        }

        $table = new StandingsTable(...$rows);

        $fixtures = [];
        foreach ($playedFixtures as $fixture) {
            $fixtures[] = $fixture;
            $table->apply($fixture->homeTeamId, $fixture->awayTeamId, $fixture->score);
        }

        $resolver = new HeadToHeadResolver($fixtures);
        $table->rank($comparator ?? self::chain(self::defaultComparators($resolver)));

        return $table;
    }

    /**
     * Premier League tiebreaker order:
     *   1. Points (desc)
     *   2. Goal difference (desc)
     *   3. Goals for (desc)
     *   4. Head-to-head points (desc)
     *   5. Head-to-head goal difference (desc)
     *   6. Head-to-head goals for (desc)
     *   7. Team name (asc, for total determinism)
     *
     * Head-to-head rules only break ties between the two specific rows being compared
     * (PL semantics) — they delegate to the resolver, which scans only fixtures
     * played between those two teams.
     *
     * @return list<callable(StandingRow, StandingRow): int>
     */
    public static function defaultComparators(HeadToHeadResolver $h2h): array
    {
        return [
            static fn (StandingRow $a, StandingRow $b): int => $b->points() <=> $a->points(),
            static fn (StandingRow $a, StandingRow $b): int => $b->goalDifference() <=> $a->goalDifference(),
            static fn (StandingRow $a, StandingRow $b): int => $b->goalsFor <=> $a->goalsFor,
            static function (StandingRow $a, StandingRow $b) use ($h2h): int {
                $r = $h2h->pointsBetween($a->teamId, $b->teamId);

                return $r['b'] <=> $r['a'];
            },
            static function (StandingRow $a, StandingRow $b) use ($h2h): int {
                $r = $h2h->goalDifferenceBetween($a->teamId, $b->teamId);

                return $r['b'] <=> $r['a'];
            },
            static function (StandingRow $a, StandingRow $b) use ($h2h): int {
                $r = $h2h->goalsForBetween($a->teamId, $b->teamId);

                return $r['b'] <=> $r['a'];
            },
            static fn (StandingRow $a, StandingRow $b): int => strcmp($a->teamName, $b->teamName),
        ];
    }

    /**
     * Compose an ordered list of comparators into a single comparator.
     *
     * @param  list<callable(StandingRow, StandingRow): int>  $comparators
     * @return callable(StandingRow, StandingRow): int
     */
    public static function chain(array $comparators): callable
    {
        return static function (StandingRow $a, StandingRow $b) use ($comparators): int {
            foreach ($comparators as $cmp) {
                $r = $cmp($a, $b);
                if ($r !== 0) {
                    return $r;
                }
            }

            return 0;
        };
    }
}
