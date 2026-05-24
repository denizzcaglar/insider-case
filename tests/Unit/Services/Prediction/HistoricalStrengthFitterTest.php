<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Prediction;

use App\Domain\ValueObjects\TeamStrength;
use App\Services\Prediction\HistoricalStrengthFitter;
use PHPUnit\Framework\TestCase;

final class HistoricalStrengthFitterTest extends TestCase
{
    /**
     * @return array<int, TeamStrength>
     */
    private function seeds(): array
    {
        return [
            1 => new TeamStrength(1, 'A', 'AAA', attack: 85, defense: 80, homeAdvantage: 1.1),
            2 => new TeamStrength(2, 'B', 'BBB', attack: 75, defense: 70, homeAdvantage: 1.1),
        ];
    }

    public function test_no_matches_returns_seed_prior_exactly(): void
    {
        $fitter = new HistoricalStrengthFitter();

        $out = $fitter->fit($this->seeds(), []);

        self::assertEqualsWithDelta(85.0, $out[1]['attack'], 0.001);
        self::assertEqualsWithDelta(80.0, $out[1]['defense'], 0.001);
        self::assertEqualsWithDelta(75.0, $out[2]['attack'], 0.001);
        self::assertEqualsWithDelta(70.0, $out[2]['defense'], 0.001);
    }

    public function test_very_large_kappa_shrinks_everything_back_to_the_seed(): void
    {
        $fitter = new HistoricalStrengthFitter(shrinkageKappa: 1_000_000.0);

        $matches = [
            $this->match(1, 2, 9, 0, '2024-01-01'),
            $this->match(2, 1, 0, 9, '2024-02-01'),
        ];

        $out = $fitter->fit($this->seeds(), $matches, referenceDate: '2024-03-01');

        // Despite the lopsided results, huge kappa drowns out the fit.
        self::assertEqualsWithDelta(85.0, $out[1]['attack'], 0.1);
        self::assertEqualsWithDelta(80.0, $out[1]['defense'], 0.1);
    }

    public function test_dominant_team_ends_with_stronger_attack_and_defense_than_dominated_team(): void
    {
        // Three teams with identical seed strengths so any divergence is purely
        // from the historical data. Team 1 thrashes team 3 in every meeting.
        // Team 2 is in the middle (split results).
        $equalSeeds = [
            1 => new TeamStrength(1, 'A', 'AAA', attack: 80, defense: 80, homeAdvantage: 1.1),
            2 => new TeamStrength(2, 'B', 'BBB', attack: 80, defense: 80, homeAdvantage: 1.1),
            3 => new TeamStrength(3, 'C', 'CCC', attack: 80, defense: 80, homeAdvantage: 1.1),
        ];

        $fitter = new HistoricalStrengthFitter(shrinkageKappa: 2.0);

        $matches = [
            // Team 1 dominates team 3.
            $this->match(1, 3, 5, 0, '2024-01-01'),
            $this->match(3, 1, 0, 4, '2024-02-01'),
            // Team 1 also beats team 2 modestly.
            $this->match(1, 2, 2, 1, '2024-03-01'),
            $this->match(2, 1, 1, 2, '2024-04-01'),
            // Team 2 beats team 3.
            $this->match(2, 3, 3, 0, '2024-05-01'),
            $this->match(3, 2, 0, 3, '2024-06-01'),
        ];

        $out = $fitter->fit($equalSeeds, $matches, referenceDate: '2024-07-01');

        self::assertGreaterThan(
            $out[3]['attack'],
            $out[1]['attack'],
            'Team 1 (who hammered team 3) should rate higher in attack than team 3.',
        );
        self::assertGreaterThan(
            $out[3]['defense'],
            $out[1]['defense'],
            'Team 1 (who shut team 3 out) should rate higher in defense than team 3.',
        );
    }

    public function test_time_decay_means_old_matches_count_for_less(): void
    {
        // Two fitters with the same data, but the old-bias case has all matches set
        // to 5 years ago, so under exponential decay their contribution is tiny.
        $matches = [
            $this->match(1, 2, 9, 0, '2019-01-01'),
            $this->match(1, 2, 9, 0, '2019-02-01'),
            $this->match(2, 1, 0, 9, '2019-03-01'),
            $this->match(2, 1, 0, 9, '2019-04-01'),
        ];

        $fitter = new HistoricalStrengthFitter(shrinkageKappa: 2.0);

        $recent = $fitter->fit($this->seeds(), $this->shiftDates($matches, '2024-05-01'), referenceDate: '2024-06-01');
        $old = $fitter->fit($this->seeds(), $matches, referenceDate: '2024-06-01');

        // Recent has stronger fit movement away from seed; old should be much closer to seed.
        $recentDelta = abs($recent[1]['attack'] - 85.0);
        $oldDelta = abs($old[1]['attack'] - 85.0);
        self::assertGreaterThan($oldDelta, $recentDelta, 'Recent matches should move the fit more than old ones.');
    }

    public function test_output_keys_match_input_seed_keys(): void
    {
        $fitter = new HistoricalStrengthFitter();
        $out = $fitter->fit($this->seeds(), []);

        self::assertSame([1, 2], array_keys($out));
        foreach ($out as $row) {
            self::assertArrayHasKey('attack', $row);
            self::assertArrayHasKey('defense', $row);
        }
    }

    /**
     * @return array{home_team_id:int, away_team_id:int, home_goals:int, away_goals:int, played_at:string, home_advantage:float}
     */
    private function match(int $home, int $away, int $hg, int $ag, string $date): array
    {
        return [
            'home_team_id' => $home,
            'away_team_id' => $away,
            'home_goals' => $hg,
            'away_goals' => $ag,
            'played_at' => $date,
            'home_advantage' => 1.1,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $matches
     * @return list<array<string, mixed>>
     */
    private function shiftDates(array $matches, string $target): array
    {
        return array_map(static function (array $m) use ($target): array {
            $m['played_at'] = $target;
            return $m;
        }, $matches);
    }
}
