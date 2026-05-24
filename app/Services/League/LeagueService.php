<?php

declare(strict_types=1);

namespace App\Services\League;

use App\Domain\Contracts\ChampionshipPredictor;
use App\Domain\ValueObjects\PlayedFixture;
use App\Domain\ValueObjects\Score;
use App\Domain\ValueObjects\StandingsTable;
use App\Domain\ValueObjects\TeamStrength;
use App\Models\Fixture;
use App\Models\MatchCommentary;
use App\Models\PredictionSnapshot;
use App\Models\Season;
use App\Models\Team;
use App\Services\Fixtures\FixtureGenerator;
use App\Services\Prediction\EffectiveStrengthBuilder;
use App\Services\Prediction\PredictionCacheStore;
use App\Services\Simulation\StatisticalMatchSimulator;
use App\Services\Standings\StandingsCalculator;
use App\Support\SeededRng;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Orchestrates state changes to a season's match schedule.
 *
 * Responsibilities: lazily generating fixtures, simulating weeks via the fast engine,
 * editing recorded results, and resetting the season. Standings are NOT cached here —
 * they are always recomputed by {@see \App\Services\Standings\StandingsCalculator}
 * from the persisted fixtures.
 */
final class LeagueService
{
    public const SNAPSHOT_ITERATIONS = 10000;

    public function __construct(
        private readonly FixtureGenerator $fixtureGenerator,
        private readonly PredictionCacheStore $predictionCache,
        private readonly ChampionshipPredictor $predictor,
        private readonly EffectiveStrengthBuilder $strengthBuilder,
        private readonly StandingsCalculator $standingsCalculator = new StandingsCalculator(),
    ) {
    }

    /**
     * Compute the current standings for a season from its played fixtures.
     */
    public function standingsFor(Season $season): StandingsTable
    {
        $teams = Team::orderBy('id')
            ->get()
            ->map(fn (Team $t) => TeamStrength::fromTeam($t))
            ->all();

        $playedFixtures = Fixture::query()
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

        return $this->standingsCalculator->calculate($teams, $playedFixtures);
    }

    /**
     * Simulate every match in the next unplayed week.
     *
     * @return Collection<int, Fixture> The freshly played fixtures.
     */
    public function nextWeek(Season $season): Collection
    {
        $this->ensureFixturesExist($season);

        $week = $this->currentWeek($season);
        if ($week === null) {
            throw new RuntimeException('Season is already complete.');
        }

        $played = $this->playWeek($season, $week);
        $this->predictionCache->bustForSeason((int) $season->id);
        $this->snapshotPredictionsFor($season, $week);

        return $played;
    }

    /**
     * Simulate every remaining week, returning the freshly played fixtures grouped by week.
     *
     * @return array<int, Collection<int, Fixture>> Keyed by week number.
     */
    public function playAll(Season $season): array
    {
        $this->ensureFixturesExist($season);

        $byWeek = [];
        while (($week = $this->currentWeek($season)) !== null) {
            $byWeek[$week] = $this->playWeek($season, $week);
        }

        return $byWeek;
    }

    /**
     * Amend a fixture's result. Marks the fixture as played even if it wasn't already.
     */
    public function editResult(Fixture $fixture, int $homeGoals, int $awayGoals): Fixture
    {
        if ($homeGoals < 0 || $awayGoals < 0) {
            throw new RuntimeException('Goals cannot be negative.');
        }

        $fixture->update([
            'played' => true,
            'home_goals' => $homeGoals,
            'away_goals' => $awayGoals,
            'simulated_at' => $fixture->simulated_at ?? now(),
        ]);

        $this->predictionCache->bustForSeason((int) $fixture->season_id);

        // The cached commentary describes a score that no longer applies. Drop it;
        // the next /commentary call will regenerate against the new score.
        MatchCommentary::where('fixture_id', $fixture->id)->delete();

        // Edits change state at every later week too, so re-snapshot every fully-played
        // week. Each snapshot reuses the cached predictor result after the first compute.
        foreach ($this->fullyPlayedWeeks($fixture->season) as $week) {
            $this->snapshotPredictionsFor($fixture->season, $week);
        }

        return $fixture->fresh();
    }

    /**
     * Reset the season: delete all fixtures, regenerate the schedule, and roll a new RNG seed.
     *
     * Pass an explicit $seed for reproducible re-runs (used by tests and the data-refresh flow).
     */
    public function reset(Season $season, ?string $seed = null): void
    {
        DB::transaction(function () use ($season, $seed): void {
            Fixture::where('season_id', $season->id)->delete();
            PredictionSnapshot::where('season_id', $season->id)->delete();

            $teams = Team::orderBy('id')->get();
            if ($teams->count() < 2) {
                throw new RuntimeException('Need at least 2 teams to reset a season.');
            }

            $this->fixtureGenerator->generateForSeason($season, $teams);
            $season->update(['rng_seed' => $seed ?? Str::random(16)]);
        });

        $this->predictionCache->bustForSeason((int) $season->id);
    }

    /**
     * The first week that still has at least one unplayed fixture, or null if the season is over.
     */
    public function currentWeek(Season $season): ?int
    {
        $min = Fixture::query()
            ->where('season_id', $season->id)
            ->where('played', false)
            ->min('week');

        return $min !== null ? (int) $min : null;
    }

    /**
     * @return Collection<int, Fixture>
     */
    private function playWeek(Season $season, int $week): Collection
    {
        $simulator = new StatisticalMatchSimulator(
            new SeededRng(($season->rng_seed ?? 'unseeded') . ':' . $week),
        );

        // One source of truth for "how strong is each team right now": the same
        // EffectiveStrengthBuilder the predictor uses. This blends the historical
        // fit with the EWMA form computed from already-played weeks 1..N-1, so the
        // simulator and the form tracker agree on what "expected" means.
        $strengths = $this->strengthBuilder->build($season)['strengths'];

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->where('week', $week)
            ->where('played', false)
            ->with(['homeTeam', 'awayTeam'])
            ->orderBy('id')
            ->get();

        DB::transaction(function () use ($simulator, $fixtures, $strengths): void {
            foreach ($fixtures as $fixture) {
                $result = $simulator->simulate(
                    $strengths[$fixture->home_team_id],
                    $strengths[$fixture->away_team_id],
                );

                $fixture->update([
                    'played' => true,
                    'home_goals' => $result->score->home,
                    'away_goals' => $result->score->away,
                    'simulated_at' => now(),
                ]);
            }
        });

        return $fixtures->fresh()->load(['homeTeam', 'awayTeam']);
    }

    /**
     * Generate fixtures and assign a seed on first use, so the standard flow
     * (migrate:fresh --seed → next-week) works without an explicit reset.
     */
    private function ensureFixturesExist(Season $season): void
    {
        $exists = Fixture::where('season_id', $season->id)->exists();
        if ($exists) {
            return;
        }

        $teams = Team::orderBy('id')->get();
        if ($teams->count() < 2) {
            throw new RuntimeException('Need at least 2 teams to start a season.');
        }

        $this->fixtureGenerator->generateForSeason($season, $teams);

        if ($season->rng_seed === null) {
            $season->update(['rng_seed' => Str::random(16)]);
            $season->refresh();
        }
    }

    /**
     * Record the current title probabilities as a snapshot for the given week.
     * Uses a deterministic, season-scoped seed so the chart line stays stable
     * across re-renders.
     *
     * Failures are logged but never raised — the user-facing operation (playing
     * a week, editing a result) must not fail because the chart couldn't update.
     */
    private function snapshotPredictionsFor(Season $season, int $weekNumber): void
    {
        try {
            $seed = "snapshot:{$season->id}:week{$weekNumber}";
            $result = $this->predictor->predict($season, self::SNAPSHOT_ITERATIONS, $seed);

            foreach ($result->titleProbabilities as $teamId => $probability) {
                PredictionSnapshot::updateOrCreate(
                    [
                        'season_id' => $season->id,
                        'week_number' => $weekNumber,
                        'team_id' => $teamId,
                    ],
                    ['probability' => round($probability, 2)],
                );
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @return list<int> week numbers where every fixture in that week has played=true
     */
    private function fullyPlayedWeeks(Season $season): array
    {
        return Fixture::query()
            ->where('season_id', $season->id)
            ->selectRaw('week, COUNT(*) AS total, SUM(played) AS played_count')
            ->groupBy('week')
            ->havingRaw('total = played_count')
            ->orderBy('week')
            ->pluck('week')
            ->map(static fn ($w) => (int) $w)
            ->all();
    }
}
