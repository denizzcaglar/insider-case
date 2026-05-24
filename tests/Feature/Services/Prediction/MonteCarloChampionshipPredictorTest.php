<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Prediction;

use App\Domain\Contracts\ChampionshipPredictor;
use App\Models\Fixture;
use App\Models\Season;
use App\Services\League\LeagueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class MonteCarloChampionshipPredictorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function predictor(): ChampionshipPredictor
    {
        return app(ChampionshipPredictor::class);
    }

    private function league(): LeagueService
    {
        return app(LeagueService::class);
    }

    private function season(): Season
    {
        return Season::firstOrFail();
    }

    public function test_probabilities_sum_to_one_hundred_within_float_tolerance(): void
    {
        $this->league()->reset($this->season(), seed: 'sum-test');

        $result = $this->predictor()->predict($this->season(), iterations: 500, seed: 'sum-test');

        $sum = array_sum($result->titleProbabilities);
        self::assertEqualsWithDelta(100.0, $sum, 0.01);
    }

    public function test_same_seed_produces_identical_probabilities(): void
    {
        $this->league()->reset($this->season(), seed: 'determinism');

        $a = $this->predictor()->predict($this->season(), iterations: 200, seed: 'predictor-seed');
        $b = $this->predictor()->predict($this->season(), iterations: 200, seed: 'predictor-seed');

        self::assertSame($a->titleProbabilities, $b->titleProbabilities);
    }

    public function test_different_seeds_produce_different_probabilities(): void
    {
        $this->league()->reset($this->season(), seed: 'shared-fixtures');

        $a = $this->predictor()->predict($this->season(), iterations: 200, seed: 'seed-A');
        $b = $this->predictor()->predict($this->season(), iterations: 200, seed: 'seed-B');

        self::assertNotSame($a->titleProbabilities, $b->titleProbabilities);
    }

    public function test_season_already_complete_returns_one_hundred_for_winner_and_zero_for_others(): void
    {
        $season = $this->season();
        $this->league()->reset($season, seed: 'completed');
        $this->league()->playAll($season);

        $result = $this->predictor()->predict($season, iterations: 100, seed: 'whatever');

        $sorted = $result->titleProbabilities;
        rsort($sorted);
        self::assertSame(100.0, $sorted[0]);
        self::assertSame([0.0, 0.0, 0.0], array_slice($sorted, 1));
    }

    public function test_zero_iterations_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->predictor()->predict($this->season(), iterations: 0);
    }

    public function test_record_returns_metadata_about_the_run(): void
    {
        $this->league()->reset($this->season(), seed: 'meta');

        $result = $this->predictor()->predict($this->season(), iterations: 50, seed: 'explicit-seed');

        self::assertSame(50, $result->iterations);
        self::assertSame('explicit-seed', $result->seed);
    }

    public function test_editing_a_result_changes_predictions_with_same_seed(): void
    {
        $season = $this->season();
        $this->league()->reset($season, seed: 'edit-vs-predict');

        // Play first 4 weeks so we're in "prediction time" with games remaining.
        for ($i = 0; $i < 4; $i++) {
            $this->league()->nextWeek($season);
        }

        $before = $this->predictor()->predict($season, iterations: 300, seed: 'stable-predictor-seed');

        // Pick a played fixture and rewrite it as a one-sided drubbing for the home side.
        $target = Fixture::played()->orderBy('id')->first();
        $this->league()->editResult($target, 9, 0);

        $after = $this->predictor()->predict($season, iterations: 300, seed: 'stable-predictor-seed');

        self::assertNotSame(
            $before->titleProbabilities,
            $after->titleProbabilities,
            'Editing a result must change predictions when the predictor seed is held constant.',
        );
    }

    public function test_strong_team_beats_weak_team_in_title_odds(): void
    {
        $season = $this->season();
        $this->league()->reset($season, seed: 'priors');

        $result = $this->predictor()->predict($season, iterations: 1000, seed: 'priors-pred');

        // City and Liverpool are both top contenders; Chelsea is clearly the weakest
        // (attack 80, defense 76 — the only team with sub-80 defense).
        $cityId = \App\Models\Team::where('short_name', 'MCI')->value('id');
        $chelseaId = \App\Models\Team::where('short_name', 'CHE')->value('id');

        self::assertGreaterThan(
            $result->titleProbabilities[$chelseaId],
            $result->titleProbabilities[$cityId],
            'A strong team should have higher title odds than the weakest team.',
        );
    }
}
