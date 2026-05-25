<?php

declare(strict_types=1);

namespace Tests\Feature\Services\League;

use App\Domain\ValueObjects\MatchEvent as EventVO;
use App\Domain\ValueObjects\MatchResult;
use App\Domain\ValueObjects\MatchResultWithEvents;
use App\Domain\ValueObjects\Score;
use App\Models\Fixture;
use App\Models\MatchEvent;
use App\Models\Season;
use App\Services\League\LeagueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RecordWatchedFixtureTest extends TestCase
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

    private function makeResult(int $home, int $away, array $events): MatchResultWithEvents
    {
        return new MatchResultWithEvents(
            new MatchResult(new Score($home, $away)),
            $events,
        );
    }

    public function test_records_score_and_events_in_one_transaction(): void
    {
        // Generate the schedule (next-week creates it lazily).
        $this->service()->nextWeek($this->season());

        // Pick an unplayed fixture; reset its played flag for the test.
        $fixture = Fixture::query()
            ->where('season_id', $this->season()->id)
            ->where('played', false)
            ->orderBy('id')
            ->firstOrFail();

        $homeScorer = \App\Models\Player::where('team_id', $fixture->home_team_id)->where('position', 'FWD')->firstOrFail();
        $awayShooter = \App\Models\Player::where('team_id', $fixture->away_team_id)->where('position', 'FWD')->firstOrFail();
        $homeKeeper = \App\Models\Player::where('team_id', $fixture->home_team_id)->where('position', 'GK')->firstOrFail();

        $result = $this->makeResult(2, 1, [
            new EventVO(0, EventVO::TYPE_KICKOFF),
            new EventVO(523, EventVO::TYPE_GOAL, teamId: $fixture->home_team_id, playerId: $homeScorer->id, detail: ['xg' => 0.18]),
            new EventVO(1834, EventVO::TYPE_SAVE, teamId: $fixture->away_team_id, playerId: $awayShooter->id, detail: ['xg' => 0.22, 'keeper_id' => $homeKeeper->id]),
            new EventVO(2700, EventVO::TYPE_HALFTIME, detail: ['score_home' => 1, 'score_away' => 0]),
            new EventVO(4012, EventVO::TYPE_GOAL, teamId: $fixture->home_team_id, playerId: $homeScorer->id, detail: ['xg' => 0.31]),
            new EventVO(4890, EventVO::TYPE_GOAL, teamId: $fixture->away_team_id, playerId: $awayShooter->id, detail: ['xg' => 0.25]),
            new EventVO(5400, EventVO::TYPE_FULLTIME, detail: ['score_home' => 2, 'score_away' => 1]),
        ]);

        $this->service()->recordWatchedFixture($fixture, $result);

        $fixture->refresh();
        self::assertTrue((bool) $fixture->played);
        self::assertSame(2, (int) $fixture->home_goals);
        self::assertSame(1, (int) $fixture->away_goals);
        self::assertNotNull($fixture->simulated_at);

        $events = $fixture->events;
        self::assertCount(7, $events);
        self::assertSame(EventVO::TYPE_KICKOFF, $events[0]->type);
        self::assertSame(0, $events[0]->second);
        self::assertSame(EventVO::TYPE_FULLTIME, $events[6]->type);
        self::assertSame(5400, $events[6]->second);
    }

    public function test_player_id_and_team_id_attribution_persists(): void
    {
        $this->service()->nextWeek($this->season());

        $fixture = Fixture::query()
            ->where('season_id', $this->season()->id)
            ->where('played', false)
            ->orderBy('id')
            ->firstOrFail();

        $scorer = \App\Models\Player::where('team_id', $fixture->home_team_id)
            ->where('position', 'FWD')
            ->firstOrFail();

        $result = $this->makeResult(1, 0, [
            new EventVO(0, EventVO::TYPE_KICKOFF),
            new EventVO(1200, EventVO::TYPE_GOAL, teamId: $fixture->home_team_id, playerId: $scorer->id, detail: ['xg' => 0.4]),
            new EventVO(5400, EventVO::TYPE_FULLTIME, detail: ['score_home' => 1, 'score_away' => 0]),
        ]);

        $this->service()->recordWatchedFixture($fixture, $result);

        $goal = MatchEvent::where('fixture_id', $fixture->id)->where('type', 'goal')->firstOrFail();
        self::assertSame($fixture->home_team_id, (int) $goal->team_id);
        self::assertSame($scorer->id, (int) $goal->player_id);
        self::assertSame(0.4, $goal->detail['xg']);
    }

    public function test_busts_prediction_cache_for_the_season(): void
    {
        // Warm the cache by running a prediction.
        $this->service()->nextWeek($this->season());
        $predictor = app(\App\Domain\Contracts\ChampionshipPredictor::class);
        $first = $predictor->predict($this->season(), 100, 'warm');

        $fixture = Fixture::query()
            ->where('season_id', $this->season()->id)
            ->where('played', false)
            ->orderBy('id')
            ->firstOrFail();

        $this->service()->recordWatchedFixture($fixture, $this->makeResult(3, 0, [
            new EventVO(0, EventVO::TYPE_KICKOFF),
            new EventVO(5400, EventVO::TYPE_FULLTIME, detail: ['score_home' => 3, 'score_away' => 0]),
        ]));

        // After the cache bust, predicting again with the same (iterations, seed)
        // returns a result that reflects the new fixture outcome — if the cache
        // had not been invalidated the call would have returned $first verbatim.
        $second = $predictor->predict($this->season(), 100, 'warm');
        $homeId = $fixture->home_team_id;
        self::assertGreaterThan(
            $first->titleProbabilities[$homeId] ?? 0.0,
            $second->titleProbabilities[$homeId] ?? 0.0,
            'Recording a 3-0 home win should raise the home team title probability.',
        );
    }
}
