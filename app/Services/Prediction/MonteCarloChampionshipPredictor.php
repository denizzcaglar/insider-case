<?php

declare(strict_types=1);

namespace App\Services\Prediction;

use App\Domain\Contracts\ChampionshipPredictor;
use App\Domain\Contracts\MatchSimulator;
use App\Domain\ValueObjects\PlayedFixture;
use App\Domain\ValueObjects\PredictionResult;
use App\Domain\ValueObjects\Score;
use App\Models\Fixture;
use App\Models\Season;
use App\Services\Standings\HeadToHeadResolver;
use App\Services\Standings\StandingsCalculator;
use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Monte Carlo championship predictor.
 *
 * For each iteration: clone the played-fixtures base table, simulate every remaining
 * fixture with the fast engine, rank, and tally the winner. The played portion of the
 * table is computed once per call and cloned per iteration — never recomputed inside
 * the inner loop.
 *
 * Team strengths are sourced from {@see EffectiveStrengthBuilder}, which blends
 * the static seed values with historical-fit priors and current-season EWMA form.
 *
 * Determinism: a string seed is hashed into the simulator's RNG so identical
 * (season, seed, iterations) triples produce identical title probabilities.
 */
final class MonteCarloChampionshipPredictor implements ChampionshipPredictor
{
    /** @var Closure(string $seed): MatchSimulator */
    private Closure $simulatorFactory;

    /**
     * @param  callable(string): MatchSimulator  $simulatorFactory  Builds a seeded fast simulator.
     */
    public function __construct(
        callable $simulatorFactory,
        private readonly EffectiveStrengthBuilder $strengthBuilder,
        private readonly StandingsCalculator $calculator = new StandingsCalculator(),
    ) {
        $this->simulatorFactory = Closure::fromCallable($simulatorFactory);
    }

    public function predict(Season $season, int $iterations, ?string $seed = null): PredictionResult
    {
        if ($iterations < 1) {
            throw new InvalidArgumentException("Iterations must be >= 1; got {$iterations}.");
        }

        $effectiveSeed = $seed ?? Str::random(16);

        $built = $this->strengthBuilder->build($season);
        $strengthsById = $built['strengths'];
        $breakdowns = $built['breakdowns'];
        $teamStrengths = array_values($strengthsById);

        $playedFixtures = $this->loadPlayedFixtures($season);
        $remainingFixtures = $this->loadRemainingFixtures($season);

        $baseTable = $this->calculator->calculate($teamStrengths, $playedFixtures);

        if ($remainingFixtures === []) {
            $resolver = new HeadToHeadResolver($playedFixtures);
            $baseTable->rank(
                StandingsCalculator::chain(StandingsCalculator::defaultComparators($resolver))
            );
            $winnerId = $baseTable->leader()->teamId;
            $probs = [];
            foreach ($teamStrengths as $ts) {
                $probs[$ts->id] = $ts->id === $winnerId ? 100.0 : 0.0;
            }

            return new PredictionResult($probs, $iterations, $effectiveSeed, $breakdowns);
        }

        $simulator = ($this->simulatorFactory)($effectiveSeed);

        $titleWins = [];
        foreach ($teamStrengths as $ts) {
            $titleWins[$ts->id] = 0;
        }

        for ($i = 0; $i < $iterations; $i++) {
            $table = $baseTable->clone();
            $iterationFixtures = $playedFixtures;

            foreach ($remainingFixtures as $r) {
                $matchResult = $simulator->simulate(
                    $strengthsById[$r['home_team_id']],
                    $strengthsById[$r['away_team_id']],
                );
                $table->apply($r['home_team_id'], $r['away_team_id'], $matchResult->score);
                $iterationFixtures[] = new PlayedFixture(
                    $r['home_team_id'],
                    $r['away_team_id'],
                    $matchResult->score,
                );
            }

            $resolver = new HeadToHeadResolver($iterationFixtures);
            $table->rank(
                StandingsCalculator::chain(StandingsCalculator::defaultComparators($resolver))
            );
            $titleWins[$table->leader()->teamId]++;
        }

        $probs = [];
        foreach ($titleWins as $teamId => $wins) {
            $probs[$teamId] = ($wins / $iterations) * 100.0;
        }

        return new PredictionResult($probs, $iterations, $effectiveSeed, $breakdowns);
    }

    /**
     * @return list<PlayedFixture>
     */
    private function loadPlayedFixtures(Season $season): array
    {
        return Fixture::query()
            ->where('season_id', $season->id)
            ->where('played', true)
            ->orderBy('id')
            ->get(['home_team_id', 'away_team_id', 'home_goals', 'away_goals'])
            ->map(fn (Fixture $f) => new PlayedFixture(
                (int) $f->home_team_id,
                (int) $f->away_team_id,
                new Score((int) $f->home_goals, (int) $f->away_goals),
            ))
            ->all();
    }

    /**
     * @return list<array{home_team_id:int, away_team_id:int}>
     */
    private function loadRemainingFixtures(Season $season): array
    {
        return Fixture::query()
            ->where('season_id', $season->id)
            ->where('played', false)
            ->orderBy('week')
            ->orderBy('id')
            ->get(['home_team_id', 'away_team_id'])
            ->map(fn (Fixture $f) => [
                'home_team_id' => (int) $f->home_team_id,
                'away_team_id' => (int) $f->away_team_id,
            ])
            ->all();
    }
}
