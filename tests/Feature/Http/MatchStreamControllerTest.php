<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Fixture;
use App\Models\MatchEvent;
use App\Models\Season;
use App\Services\League\LeagueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the watch endpoint's path selection: simulate-then-replay,
 * pure replay, refusal for stat-played fixtures, and the historical-season
 * read-only rule. Wire-format pacing is exercised lightly because PHPUnit
 * captures the full streamed body before any timing matters.
 */
final class MatchStreamControllerTest extends TestCase
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

    private function unplayedFixture(): Fixture
    {
        $this->service()->nextWeek($this->season());  // generates fixtures + plays week 1

        return Fixture::query()
            ->where('season_id', $this->season()->id)
            ->where('played', false)
            ->orderBy('id')
            ->firstOrFail();
    }

    public function test_watch_simulates_unplayed_fixture_persists_events_and_streams(): void
    {
        $fixture = $this->unplayedFixture();

        $response = $this->get("/api/fixtures/{$fixture->id}/watch?speed=3600");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');

        $body = $response->streamedContent();
        self::assertStringContainsString('event: match-event', $body);
        self::assertStringContainsString('event: complete', $body);

        $fixture->refresh();
        self::assertTrue((bool) $fixture->played);
        self::assertGreaterThan(0, MatchEvent::where('fixture_id', $fixture->id)->count());
    }

    public function test_replay_of_already_watched_fixture_does_not_re_simulate(): void
    {
        $fixture = $this->unplayedFixture();

        // First watch: simulate + persist.
        $this->get("/api/fixtures/{$fixture->id}/watch?speed=3600")->assertOk();

        $eventsAfterFirstWatch = MatchEvent::where('fixture_id', $fixture->id)->count();
        self::assertGreaterThan(0, $eventsAfterFirstWatch);

        $fixture->refresh();
        $scoreBefore = [$fixture->home_goals, $fixture->away_goals];

        // Second watch: should be pure replay; event count unchanged, score unchanged.
        $this->get("/api/fixtures/{$fixture->id}/watch?speed=3600")->assertOk();

        $fixture->refresh();
        self::assertSame($eventsAfterFirstWatch, MatchEvent::where('fixture_id', $fixture->id)->count());
        self::assertSame($scoreBefore[0], $fixture->home_goals);
        self::assertSame($scoreBefore[1], $fixture->away_goals);
    }

    public function test_stat_played_fixture_without_events_returns_409(): void
    {
        // playWeek (the stat path) marks fixtures played but does not insert match_events.
        $this->service()->nextWeek($this->season());

        $statPlayed = Fixture::query()
            ->where('season_id', $this->season()->id)
            ->where('played', true)
            ->orderBy('id')
            ->firstOrFail();

        self::assertSame(0, MatchEvent::where('fixture_id', $statPlayed->id)->count());

        $this->get("/api/fixtures/{$statPlayed->id}/watch")
            ->assertStatus(409);
    }

    public function test_historical_season_fixture_is_forbidden(): void
    {
        $historicalFixture = Fixture::query()
            ->whereHas('season', fn ($q) => $q->where('is_historical', true))
            ->firstOrFail();

        $this->get("/api/fixtures/{$historicalFixture->id}/watch")
            ->assertStatus(403);
    }

    public function test_events_endpoint_returns_full_event_list_after_watch(): void
    {
        $fixture = $this->unplayedFixture();
        $this->get("/api/fixtures/{$fixture->id}/watch?speed=3600")->assertOk();

        $response = $this->getJson("/api/fixtures/{$fixture->id}/events");

        $response->assertOk();
        $response->assertJsonStructure([
            'fixture_id',
            'score' => ['home', 'away'],
            'events' => [['second', 'clock', 'type']],
        ]);
    }

    public function test_events_endpoint_returns_404_when_no_events(): void
    {
        $this->service()->nextWeek($this->season());

        $statPlayed = Fixture::query()
            ->where('season_id', $this->season()->id)
            ->where('played', true)
            ->orderBy('id')
            ->firstOrFail();

        $this->getJson("/api/fixtures/{$statPlayed->id}/events")
            ->assertStatus(404);
    }

    public function test_invalid_speed_is_rejected(): void
    {
        $fixture = $this->unplayedFixture();

        $this->getJson("/api/fixtures/{$fixture->id}/watch?speed=36009")
            ->assertStatus(422);
    }
}
