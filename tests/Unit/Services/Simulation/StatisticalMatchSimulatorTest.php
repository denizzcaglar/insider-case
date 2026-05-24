<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Simulation;

use App\Domain\ValueObjects\Score;
use App\Domain\ValueObjects\TeamStrength;
use App\Services\Simulation\StatisticalMatchSimulator;
use App\Support\SeededRng;
use PHPUnit\Framework\TestCase;

final class StatisticalMatchSimulatorTest extends TestCase
{
    private function teamStrong(): TeamStrength
    {
        return new TeamStrength(1, 'Strong', 'STR', attack: 90, defense: 88, homeAdvantage: 1.15);
    }

    private function teamWeak(): TeamStrength
    {
        return new TeamStrength(2, 'Weak', 'WEK', attack: 60, defense: 58, homeAdvantage: 1.10);
    }

    private function teamEven(int $id, string $name): TeamStrength
    {
        return new TeamStrength($id, $name, substr($name, 0, 3), attack: 80, defense: 80, homeAdvantage: 1.10);
    }

    public function test_same_seed_produces_identical_result(): void
    {
        $a = new StatisticalMatchSimulator(new SeededRng('match'));
        $b = new StatisticalMatchSimulator(new SeededRng('match'));

        $resultA = $a->simulate($this->teamStrong(), $this->teamWeak());
        $resultB = $b->simulate($this->teamStrong(), $this->teamWeak());

        self::assertSame($resultA->score->home, $resultB->score->home);
        self::assertSame($resultA->score->away, $resultB->score->away);
    }

    public function test_goals_are_non_negative(): void
    {
        $sim = new StatisticalMatchSimulator(new SeededRng('nonneg-match'));

        for ($i = 0; $i < 200; $i++) {
            $result = $sim->simulate($this->teamStrong(), $this->teamWeak());
            self::assertGreaterThanOrEqual(0, $result->score->home);
            self::assertGreaterThanOrEqual(0, $result->score->away);
        }
    }

    public function test_stronger_team_wins_more_often_over_many_simulations(): void
    {
        $sim = new StatisticalMatchSimulator(new SeededRng('strong-vs-weak'));
        $strong = $this->teamStrong();
        $weak = $this->teamWeak();

        $strongWins = 0;
        $weakWins = 0;
        $runs = 500;

        for ($i = 0; $i < $runs; $i++) {
            $result = $sim->simulate($strong, $weak);
            if ($result->score->winner() === Score::HOME) {
                $strongWins++;
            } elseif ($result->score->winner() === Score::AWAY) {
                $weakWins++;
            }
        }

        self::assertGreaterThan(
            $weakWins,
            $strongWins,
            "Strong team won {$strongWins}, weak team won {$weakWins} over {$runs} runs.",
        );
    }

    public function test_home_advantage_skews_results_in_favor_of_home_side(): void
    {
        $sim = new StatisticalMatchSimulator(new SeededRng('home-advantage'));
        $home = $this->teamEven(1, 'HomeFC');
        $away = $this->teamEven(2, 'AwayFC');

        $homeWins = 0;
        $awayWins = 0;
        $runs = 500;

        for ($i = 0; $i < $runs; $i++) {
            $result = $sim->simulate($home, $away);
            if ($result->score->winner() === Score::HOME) {
                $homeWins++;
            } elseif ($result->score->winner() === Score::AWAY) {
                $awayWins++;
            }
        }

        self::assertGreaterThan(
            $awayWins,
            $homeWins,
            "Home won {$homeWins}, away won {$awayWins} over {$runs} runs with equal strengths.",
        );
    }
}
