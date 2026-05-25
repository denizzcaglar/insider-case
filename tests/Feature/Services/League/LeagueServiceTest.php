<?php

declare(strict_types=1);

namespace Tests\Feature\Services\League;

use App\Domain\ValueObjects\PlayedFixture;
use App\Domain\ValueObjects\Score;
use App\Domain\ValueObjects\TeamStrength;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use App\Services\League\LeagueService;
use App\Services\Standings\StandingsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class LeagueServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function service(): LeagueService
    {
        return app(LeagueService::class);
    }

    private function season(): Season
    {
        return Season::query()->where('is_historical', false)->orderBy('id')->firstOrFail();
    }

    /**
     * Scope a Fixture query to the simulated season under test. Necessary because
     * HistoricalSeasonSeeder loads 36 played fixtures across three historical
     * seasons; unscoped queries would mix them in with the season under test.
     */
    private function seasonFixtures()
    {
        return Fixture::query()->where('season_id', $this->season()->id);
    }

    public function test_next_week_lazily_generates_fixtures_on_first_call(): void
    {
        self::assertSame(0, $this->seasonFixtures()->count());

        $played = $this->service()->nextWeek($this->season());

        self::assertSame(12, $this->seasonFixtures()->count());
        self::assertCount(2, $played);
        self::assertSame(2, $this->seasonFixtures()->where('played', true)->count());
    }

    public function test_next_week_assigns_a_seed_on_first_use_if_none_set(): void
    {
        $season = $this->season();
        self::assertNull($season->rng_seed);

        $this->service()->nextWeek($season);

        self::assertNotNull($season->fresh()->rng_seed);
    }

    public function test_consecutive_next_week_calls_advance_through_the_schedule(): void
    {
        $season = $this->season();

        for ($i = 1; $i <= 6; $i++) {
            $played = $this->service()->nextWeek($season);
            self::assertCount(2, $played);
            self::assertSame($i * 2, $this->seasonFixtures()->where('played', true)->count());
        }

        self::assertNull($this->service()->currentWeek($season));
    }

    public function test_next_week_throws_when_season_is_complete(): void
    {
        $season = $this->season();
        $this->service()->playAll($season);

        $this->expectException(RuntimeException::class);
        $this->service()->nextWeek($season);
    }

    public function test_play_all_finishes_the_entire_season(): void
    {
        $season = $this->season();

        $byWeek = $this->service()->playAll($season);

        self::assertSame([1, 2, 3, 4, 5, 6], array_keys($byWeek));
        foreach ($byWeek as $week => $fixtures) {
            self::assertCount(2, $fixtures, "Week {$week}");
        }
        self::assertSame(12, $this->seasonFixtures()->where('played', true)->count());
        self::assertNull($this->service()->currentWeek($season));
    }

    public function test_play_all_records_a_prediction_snapshot_for_every_week(): void
    {
        $season = $this->season();

        $this->service()->playAll($season);

        $weeks = \App\Models\PredictionSnapshot::where('season_id', $season->id)
            ->distinct()
            ->orderBy('week_number')
            ->pluck('week_number')
            ->map(static fn ($w) => (int) $w)
            ->all();

        self::assertSame([1, 2, 3, 4, 5, 6], $weeks);
    }

    public function test_seeded_season_is_fully_deterministic_end_to_end(): void
    {
        $season = $this->season();

        $this->service()->reset($season, seed: 'deterministic-1');
        $this->service()->playAll($season);
        $first = $this->seasonFixtures()
            ->orderBy('id')
            ->get(['home_team_id', 'away_team_id', 'home_goals', 'away_goals'])
            ->map(fn ($f) => "{$f->home_team_id}:{$f->away_team_id}={$f->home_goals}-{$f->away_goals}")
            ->all();

        $this->service()->reset($season, seed: 'deterministic-1');
        $this->service()->playAll($season);
        $second = $this->seasonFixtures()
            ->orderBy('id')
            ->get(['home_team_id', 'away_team_id', 'home_goals', 'away_goals'])
            ->map(fn ($f) => "{$f->home_team_id}:{$f->away_team_id}={$f->home_goals}-{$f->away_goals}")
            ->all();

        self::assertSame($first, $second);
    }

    public function test_different_seeds_produce_different_outcomes(): void
    {
        $season = $this->season();

        $this->service()->reset($season, seed: 'seed-A');
        $this->service()->playAll($season);
        $scoresA = $this->seasonFixtures()->orderBy('id')->pluck('home_goals')->all();

        $this->service()->reset($season, seed: 'seed-B');
        $this->service()->playAll($season);
        $scoresB = $this->seasonFixtures()->orderBy('id')->pluck('home_goals')->all();

        self::assertNotSame($scoresA, $scoresB);
    }

    public function test_edit_result_changes_the_aggregated_standings(): void
    {
        $season = $this->season();
        $this->service()->reset($season, seed: 'edit-test');
        $this->service()->playAll($season);

        $teams = Team::all()->map(fn (Team $t) => TeamStrength::fromTeam($t))->all();
        $calculator = new StandingsCalculator();

        $before = $calculator->calculate($teams, $this->playedFixturesAsDomain());
        $target = $this->seasonFixtures()->orderBy('id')->first();

        // Edit to a huge home win regardless of previous score.
        $this->service()->editResult($target, 9, 0);

        $after = $calculator->calculate($teams, $this->playedFixturesAsDomain());

        self::assertGreaterThan(
            $before->rowFor($target->home_team_id)->goalsFor,
            $after->rowFor($target->home_team_id)->goalsFor,
        );
        self::assertGreaterThanOrEqual(
            $before->rowFor($target->home_team_id)->points(),
            $after->rowFor($target->home_team_id)->points(),
        );
    }

    public function test_reset_clears_results_and_regenerates_fixtures(): void
    {
        $season = $this->season();
        $this->service()->playAll($season);
        self::assertSame(12, $this->seasonFixtures()->where('played', true)->count());

        $this->service()->reset($season, seed: 'after-reset');

        self::assertSame(12, $this->seasonFixtures()->count());
        self::assertSame(0, $this->seasonFixtures()->where('played', true)->count());
        self::assertSame('after-reset', $season->fresh()->rng_seed);
    }

    public function test_reset_with_null_seed_assigns_a_random_seed(): void
    {
        $season = $this->season();
        $this->service()->reset($season);

        self::assertNotNull($season->fresh()->rng_seed);
    }

    public function test_total_points_invariant_holds_after_full_season(): void
    {
        $season = $this->season();
        $this->service()->reset($season, seed: 'points-invariant');
        $this->service()->playAll($season);

        $totalPoints = 0;
        $draws = 0;
        foreach ($this->seasonFixtures()->get() as $f) {
            if ($f->home_goals === $f->away_goals) {
                $totalPoints += 2;
                $draws++;
            } else {
                $totalPoints += 3;
            }
        }

        // 12 matches, win=3pts, draw=2pts.
        self::assertSame(12 * 3 - $draws, $totalPoints);
    }

    /**
     * @return list<PlayedFixture>
     */
    private function playedFixturesAsDomain(): array
    {
        return $this->seasonFixtures()
            ->where('played', true)
            ->get()
            ->map(fn (Fixture $f) => new PlayedFixture(
                $f->home_team_id,
                $f->away_team_id,
                new Score((int) $f->home_goals, (int) $f->away_goals),
            ))
            ->all();
    }
}
