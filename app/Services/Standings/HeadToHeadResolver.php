<?php

declare(strict_types=1);

namespace App\Services\Standings;

use App\Domain\ValueObjects\PlayedFixture;

/**
 * Resolves Premier-League head-to-head tiebreakers between two teams.
 *
 * Head-to-head is a relationship between two teams, not a property of a single team —
 * so this is computed pair-wise from the played fixtures, not cached on StandingRow.
 *
 * Only kicks in when teams are tied on points / goal difference / goals for; the
 * comparator chain in StandingsCalculator handles that ordering automatically.
 */
final class HeadToHeadResolver
{
    /** @var list<PlayedFixture> */
    private readonly array $fixtures;

    /**
     * @param  iterable<PlayedFixture>  $playedFixtures
     */
    public function __construct(iterable $playedFixtures)
    {
        $fixtures = [];
        foreach ($playedFixtures as $fixture) {
            $fixtures[] = $fixture;
        }

        $this->fixtures = $fixtures;
    }

    /**
     * Returns the points each team has earned in matches against the other.
     * 3 for a win, 1 for a draw — same scoring as the regular table.
     *
     * @return array{a: int, b: int}
     */
    public function pointsBetween(int $teamA, int $teamB): array
    {
        $a = 0;
        $b = 0;

        foreach ($this->matchesBetween($teamA, $teamB) as [$forA, $forB]) {
            if ($forA > $forB) {
                $a += 3;
            } elseif ($forA < $forB) {
                $b += 3;
            } else {
                $a += 1;
                $b += 1;
            }
        }

        return ['a' => $a, 'b' => $b];
    }

    /**
     * Returns the goal difference each team has in matches against the other.
     *
     * @return array{a: int, b: int}
     */
    public function goalDifferenceBetween(int $teamA, int $teamB): array
    {
        $a = 0;
        $b = 0;

        foreach ($this->matchesBetween($teamA, $teamB) as [$forA, $forB]) {
            $a += $forA - $forB;
            $b += $forB - $forA;
        }

        return ['a' => $a, 'b' => $b];
    }

    /**
     * Returns goals each team has scored in matches against the other.
     *
     * @return array{a: int, b: int}
     */
    public function goalsForBetween(int $teamA, int $teamB): array
    {
        $a = 0;
        $b = 0;

        foreach ($this->matchesBetween($teamA, $teamB) as [$forA, $forB]) {
            $a += $forA;
            $b += $forB;
        }

        return ['a' => $a, 'b' => $b];
    }

    /**
     * Yields [goals_for_A, goals_for_B] for every fixture between the two teams
     * (typically two legs — home and away).
     *
     * @return iterable<array{0: int, 1: int}>
     */
    private function matchesBetween(int $teamA, int $teamB): iterable
    {
        foreach ($this->fixtures as $fixture) {
            if ($fixture->homeTeamId === $teamA && $fixture->awayTeamId === $teamB) {
                yield [$fixture->score->home, $fixture->score->away];
            } elseif ($fixture->homeTeamId === $teamB && $fixture->awayTeamId === $teamA) {
                yield [$fixture->score->away, $fixture->score->home];
            }
        }
    }
}
