<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Prediction;

use App\Services\Prediction\CurrentFormTracker;
use PHPUnit\Framework\TestCase;

final class CurrentFormTrackerTest extends TestCase
{
    /**
     * @return array<int, array{attack: float, defense: float}>
     */
    private function priors(): array
    {
        return [
            1 => ['attack' => 80.0, 'defense' => 80.0],
            2 => ['attack' => 80.0, 'defense' => 80.0],
        ];
    }

    /**
     * @return array<int, float>
     */
    private function homeAdvantages(): array
    {
        return [1 => 1.1, 2 => 1.1];
    }

    public function test_no_current_matches_returns_neutral_form_factors(): void
    {
        $tracker = new CurrentFormTracker();
        $form = $tracker->track($this->priors(), $this->homeAdvantages(), []);

        self::assertSame(1.0, $form[1]['attack']);
        self::assertSame(1.0, $form[1]['defense']);
        self::assertSame(1.0, $form[2]['attack']);
        self::assertSame(1.0, $form[2]['defense']);
    }

    public function test_overperformance_pushes_form_attack_above_one(): void
    {
        $tracker = new CurrentFormTracker(ewmaAlpha: 0.5);

        // Team 1 hammers team 2 several times: home_goals well above the ~1.5 baseline.
        $matches = [
            $this->match(1, 2, 5, 0),
            $this->match(1, 2, 4, 1),
            $this->match(1, 2, 4, 0),
        ];

        $form = $tracker->track($this->priors(), $this->homeAdvantages(), $matches);

        self::assertGreaterThan(1.0, $form[1]['attack']);
        self::assertLessThan(1.0, $form[2]['attack']);
    }

    public function test_form_factors_are_clamped_to_a_sane_band(): void
    {
        $tracker = new CurrentFormTracker(ewmaAlpha: 0.9);

        // Wildly lopsided result. Without clamping the form attack could explode.
        $matches = [$this->match(1, 2, 20, 0)];
        $form = $tracker->track($this->priors(), $this->homeAdvantages(), $matches);

        foreach ($form as $f) {
            self::assertGreaterThanOrEqual(0.5, $f['attack']);
            self::assertLessThanOrEqual(1.8, $f['attack']);
            self::assertGreaterThanOrEqual(0.5, $f['defense']);
            self::assertLessThanOrEqual(1.8, $f['defense']);
        }
    }

    public function test_keys_match_input_prior_keys(): void
    {
        $tracker = new CurrentFormTracker();
        $form = $tracker->track($this->priors(), $this->homeAdvantages(), []);

        self::assertSame([1, 2], array_keys($form));
    }

    /**
     * @return array{home_team_id:int, away_team_id:int, home_goals:int, away_goals:int, played_at:string|null}
     */
    private function match(int $home, int $away, int $hg, int $ag): array
    {
        return [
            'home_team_id' => $home,
            'away_team_id' => $away,
            'home_goals' => $hg,
            'away_goals' => $ag,
            'played_at' => null,
        ];
    }
}
