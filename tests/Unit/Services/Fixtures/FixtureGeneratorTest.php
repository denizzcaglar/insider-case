<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Fixtures;

use App\Services\Fixtures\FixtureGenerator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class FixtureGeneratorTest extends TestCase
{
    public function test_generates_twelve_fixtures_for_four_teams(): void
    {
        $fixtures = (new FixtureGenerator())->generate([1, 2, 3, 4]);

        self::assertCount(12, $fixtures);
    }

    public function test_uses_six_weeks_with_two_matches_each(): void
    {
        $fixtures = (new FixtureGenerator())->generate([1, 2, 3, 4]);

        $byWeek = [];
        foreach ($fixtures as $f) {
            $byWeek[$f['week']] = ($byWeek[$f['week']] ?? 0) + 1;
        }

        self::assertSame([1, 2, 3, 4, 5, 6], array_keys($byWeek));
        foreach ($byWeek as $count) {
            self::assertSame(2, $count);
        }
    }

    public function test_no_team_plays_itself(): void
    {
        $fixtures = (new FixtureGenerator())->generate([1, 2, 3, 4]);

        foreach ($fixtures as $f) {
            self::assertNotSame($f['home_team_id'], $f['away_team_id']);
        }
    }

    public function test_each_ordered_pair_appears_exactly_once(): void
    {
        $fixtures = (new FixtureGenerator())->generate([1, 2, 3, 4]);

        $orderedPairs = [];
        foreach ($fixtures as $f) {
            $key = "{$f['home_team_id']}-{$f['away_team_id']}";
            $orderedPairs[$key] = ($orderedPairs[$key] ?? 0) + 1;
        }

        self::assertCount(12, $orderedPairs);
        foreach ($orderedPairs as $count) {
            self::assertSame(1, $count);
        }
    }

    public function test_each_team_plays_three_home_and_three_away(): void
    {
        $fixtures = (new FixtureGenerator())->generate([1, 2, 3, 4]);

        $home = [];
        $away = [];
        foreach ($fixtures as $f) {
            $home[$f['home_team_id']] = ($home[$f['home_team_id']] ?? 0) + 1;
            $away[$f['away_team_id']] = ($away[$f['away_team_id']] ?? 0) + 1;
        }

        foreach ([1, 2, 3, 4] as $teamId) {
            self::assertSame(3, $home[$teamId] ?? 0, "Team {$teamId} home games");
            self::assertSame(3, $away[$teamId] ?? 0, "Team {$teamId} away games");
        }
    }

    public function test_no_team_appears_twice_in_a_single_week(): void
    {
        $fixtures = (new FixtureGenerator())->generate([1, 2, 3, 4]);

        $seenByWeek = [];
        foreach ($fixtures as $f) {
            $week = $f['week'];
            $seenByWeek[$week] = $seenByWeek[$week] ?? [];

            foreach ([$f['home_team_id'], $f['away_team_id']] as $teamId) {
                self::assertNotContains(
                    $teamId,
                    $seenByWeek[$week],
                    "Team {$teamId} double-booked in week {$week}",
                );
                $seenByWeek[$week][] = $teamId;
            }
        }
    }

    public function test_two_teams_produce_two_fixtures(): void
    {
        $fixtures = (new FixtureGenerator())->generate([10, 20]);

        self::assertCount(2, $fixtures);
        self::assertSame(1, $fixtures[0]['week']);
        self::assertSame(2, $fixtures[1]['week']);
        // Second leg flips home/away.
        self::assertSame($fixtures[0]['home_team_id'], $fixtures[1]['away_team_id']);
        self::assertSame($fixtures[0]['away_team_id'], $fixtures[1]['home_team_id']);
    }

    public function test_rejects_odd_team_count(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FixtureGenerator())->generate([1, 2, 3]);
    }

    public function test_rejects_duplicate_team_ids(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FixtureGenerator())->generate([1, 2, 2, 4]);
    }
}
