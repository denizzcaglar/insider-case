<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Standings;

use App\Domain\ValueObjects\PlayedFixture;
use App\Domain\ValueObjects\Score;
use App\Domain\ValueObjects\TeamStrength;
use App\Services\Standings\StandingsCalculator;
use PHPUnit\Framework\TestCase;

final class StandingsCalculatorTest extends TestCase
{
    /** @return list<TeamStrength> */
    private function fourTeams(): array
    {
        return [
            new TeamStrength(1, 'Alpha', 'ALP', attack: 80, defense: 80, homeAdvantage: 1.10),
            new TeamStrength(2, 'Bravo', 'BRV', attack: 80, defense: 80, homeAdvantage: 1.10),
            new TeamStrength(3, 'Charlie', 'CHR', attack: 80, defense: 80, homeAdvantage: 1.10),
            new TeamStrength(4, 'Delta', 'DLT', attack: 80, defense: 80, homeAdvantage: 1.10),
        ];
    }

    public function test_no_fixtures_returns_four_zero_rows_sorted_by_name(): void
    {
        $table = (new StandingsCalculator())->calculate($this->fourTeams(), []);
        $rows = $table->rows();

        self::assertCount(4, $rows);
        self::assertSame(['Alpha', 'Bravo', 'Charlie', 'Delta'], array_map(fn ($r) => $r->teamName, $rows));
        foreach ($rows as $r) {
            self::assertSame(0, $r->played);
            self::assertSame(0, $r->points());
            self::assertSame(0, $r->goalDifference());
        }
    }

    public function test_single_match_awards_three_points_to_winner_and_zero_to_loser(): void
    {
        $fixtures = [new PlayedFixture(1, 2, new Score(2, 1))];

        $table = (new StandingsCalculator())->calculate($this->fourTeams(), $fixtures);
        $rows = $table->rows();

        // Alpha won; Charlie and Delta unplayed (0 pts, tie-broken by name); Bravo lost.
        self::assertSame('Alpha', $rows[0]->teamName);
        self::assertSame(3, $rows[0]->points());
        self::assertSame(1, $rows[0]->goalDifference());
        self::assertSame('Bravo', $rows[3]->teamName);
        self::assertSame(0, $rows[3]->points());
        self::assertSame(-1, $rows[3]->goalDifference());
    }

    public function test_draw_awards_one_point_each(): void
    {
        $fixtures = [new PlayedFixture(1, 2, new Score(1, 1))];

        $table = (new StandingsCalculator())->calculate($this->fourTeams(), $fixtures);

        self::assertSame(1, $table->rowFor(1)->points());
        self::assertSame(1, $table->rowFor(2)->points());
        self::assertSame(0, $table->rowFor(1)->goalDifference());
        self::assertSame(0, $table->rowFor(2)->goalDifference());
    }

    public function test_tiebreaker_uses_goal_difference_when_points_are_equal(): void
    {
        // Alpha beats Bravo 3-0; Charlie beats Delta 1-0. Both winners get 3 pts;
        // Alpha has GD +3 and should outrank Charlie with GD +1.
        $fixtures = [
            new PlayedFixture(1, 2, new Score(3, 0)),
            new PlayedFixture(3, 4, new Score(1, 0)),
        ];

        $table = (new StandingsCalculator())->calculate($this->fourTeams(), $fixtures);
        $rows = $table->rows();

        self::assertSame('Alpha', $rows[0]->teamName);
        self::assertSame('Charlie', $rows[1]->teamName);
    }

    public function test_tiebreaker_uses_goals_for_when_points_and_gd_are_equal(): void
    {
        // Both winners win by 1 goal, same GD; Alpha 3-2, Charlie 1-0.
        // Alpha has GF=3, Charlie has GF=1 — Alpha outranks Charlie.
        $fixtures = [
            new PlayedFixture(1, 2, new Score(3, 2)),
            new PlayedFixture(3, 4, new Score(1, 0)),
        ];

        $table = (new StandingsCalculator())->calculate($this->fourTeams(), $fixtures);
        $rows = $table->rows();

        self::assertSame('Alpha', $rows[0]->teamName);
        self::assertSame('Charlie', $rows[1]->teamName);
    }

    public function test_tiebreaker_falls_through_to_team_name_when_everything_else_ties(): void
    {
        // No matches played: all rows identical except name. Alphabetical order.
        $teams = [
            new TeamStrength(1, 'Zulu', 'ZUL', attack: 80, defense: 80, homeAdvantage: 1.10),
            new TeamStrength(2, 'Alpha', 'ALP', attack: 80, defense: 80, homeAdvantage: 1.10),
            new TeamStrength(3, 'Mike', 'MIK', attack: 80, defense: 80, homeAdvantage: 1.10),
        ];

        $table = (new StandingsCalculator())->calculate($teams, []);
        $rows = $table->rows();

        self::assertSame(['Alpha', 'Mike', 'Zulu'], array_map(fn ($r) => $r->teamName, $rows));
    }

    public function test_aggregates_multiple_matches_correctly(): void
    {
        // Alpha plays 3 games: W 3-0 vs B, D 1-1 vs C, L 0-2 vs D
        // -> P=3, W=1, D=1, L=1, GF=4, GA=3, GD=+1, Pts=4
        $fixtures = [
            new PlayedFixture(1, 2, new Score(3, 0)),  // Alpha 3-0 Bravo
            new PlayedFixture(1, 3, new Score(1, 1)),  // Alpha 1-1 Charlie
            new PlayedFixture(4, 1, new Score(2, 0)),  // Delta 2-0 Alpha
        ];

        $table = (new StandingsCalculator())->calculate($this->fourTeams(), $fixtures);
        $alpha = $table->rowFor(1);

        self::assertSame(3, $alpha->played);
        self::assertSame(1, $alpha->won);
        self::assertSame(1, $alpha->drawn);
        self::assertSame(1, $alpha->lost);
        self::assertSame(4, $alpha->goalsFor);
        self::assertSame(3, $alpha->goalsAgainst);
        self::assertSame(1, $alpha->goalDifference());
        self::assertSame(4, $alpha->points());
    }

    public function test_custom_comparator_overrides_default(): void
    {
        $fixtures = [new PlayedFixture(1, 2, new Score(5, 0))];

        // Comparator that sorts by team name ascending only, ignoring points.
        $byName = static fn ($a, $b) => strcmp($a->teamName, $b->teamName);

        $table = (new StandingsCalculator())
            ->calculate($this->fourTeams(), $fixtures, $byName);

        self::assertSame(
            ['Alpha', 'Bravo', 'Charlie', 'Delta'],
            array_map(fn ($r) => $r->teamName, $table->rows()),
        );
    }
}
