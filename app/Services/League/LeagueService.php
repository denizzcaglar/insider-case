<?php

declare(strict_types=1);

namespace App\Services\League;

use App\Domain\Contracts\ChampionshipPredictor;
use App\Domain\ValueObjects\MatchEvent as EventVO;
use App\Domain\ValueObjects\MatchResultWithEvents;
use App\Domain\ValueObjects\PlayedFixture;
use App\Domain\ValueObjects\Score;
use App\Domain\ValueObjects\StandingsTable;
use App\Domain\ValueObjects\TeamStrength;
use App\Models\Fixture;
use App\Models\MatchCommentary;
use App\Models\MatchEvent;
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

// Standings are never cached; StandingsCalculator rebuilds from played fixtures.
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

    /** @return Collection<int, Fixture> */
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

    /** @return array<int, Collection<int, Fixture>> */
    public function playAll(Season $season): array
    {
        $this->ensureFixturesExist($season);

        $byWeek = [];
        while (($week = $this->currentWeek($season)) !== null) {
            $byWeek[$week] = $this->playWeek($season, $week);
            $this->predictionCache->bustForSeason((int) $season->id);
            $this->snapshotPredictionsFor($season, $week);
        }

        return $byWeek;
    }

    public function recordWatchedFixture(Fixture $fixture, MatchResultWithEvents $r): void
    {
        DB::transaction(function () use ($fixture, $r): void {
            $fixture->update([
                'played' => true,
                'home_goals' => $r->result->score->home,
                'away_goals' => $r->result->score->away,
                'simulated_at' => now(),
            ]);

            if ($r->events !== []) {
                $now = now();
                $rows = array_map(static fn (EventVO $e) => [
                    'fixture_id' => $fixture->id,
                    'second' => $e->second,
                    'type' => $e->type,
                    'team_id' => $e->teamId,
                    'player_id' => $e->playerId,
                    'detail' => $e->detail === [] ? null : json_encode($e->detail),
                    'created_at' => $now,
                ], $r->events);

                MatchEvent::insert($rows);
            }

            $this->predictionCache->bustForSeason((int) $fixture->season_id);

            if ($this->isWeekComplete($fixture)) {
                $this->snapshotPredictionsFor($fixture->season, (int) $fixture->week);
            }
        });
    }

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

        MatchCommentary::where('fixture_id', $fixture->id)->delete();
        MatchEvent::where('fixture_id', $fixture->id)->delete();

        // Re-snapshot every fully-played week: an edit changes downstream weeks too.
        foreach ($this->fullyPlayedWeeks($fixture->season) as $week) {
            $this->snapshotPredictionsFor($fixture->season, $week);
        }

        return $fixture->fresh();
    }

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

        // Single source of truth for team strength (predictor uses the same builder).
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

    // Season-scoped seed keeps the chart line stable across re-renders.
    // Failures are logged so playWeek/edit never breaks if the predictor errors.
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

    private function isWeekComplete(Fixture $fixture): bool
    {
        $remaining = Fixture::query()
            ->where('season_id', $fixture->season_id)
            ->where('week', $fixture->week)
            ->where('played', false)
            ->count();

        return $remaining === 0;
    }

    /** @return list<int> */
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
