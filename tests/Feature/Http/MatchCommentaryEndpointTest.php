<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Fixture;
use App\Models\MatchCommentary;
use App\Models\Season;
use App\Services\League\LeagueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MatchCommentaryEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['commentary.api_key' => 'test-key']);
    }

    public function test_happy_path_returns_200_with_commentary_payload(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'A tense win for the home side.']]]]],
            ], 200),
        ]);

        $fixture = $this->firstPlayedFixture();

        $response = $this->getJson("/api/fixtures/{$fixture->id}/commentary");

        $response->assertOk()
            ->assertJson([
                'fixture_id' => (int) $fixture->id,
                'home_goals' => (int) $fixture->home_goals,
                'away_goals' => (int) $fixture->away_goals,
                'commentary' => 'A tense win for the home side.',
            ]);

        self::assertSame(1, MatchCommentary::where('fixture_id', $fixture->id)->count());
    }

    public function test_unplayed_fixture_returns_422(): void
    {
        Http::fake();
        app(LeagueService::class)->reset(Season::current(), 'fresh');
        $unplayed = Fixture::where('played', false)->orderBy('id')->firstOrFail();

        $this->getJson("/api/fixtures/{$unplayed->id}/commentary")
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_unknown_fixture_returns_404(): void
    {
        Http::fake();
        $this->getJson('/api/fixtures/99999/commentary')->assertStatus(404);
        Http::assertNothingSent();
    }

    public function test_missing_api_key_returns_503(): void
    {
        config(['commentary.api_key' => '']);
        Http::fake();

        $fixture = $this->firstPlayedFixture();

        $this->getJson("/api/fixtures/{$fixture->id}/commentary")
            ->assertStatus(503)
            ->assertJsonPath('message', 'Commentary service unavailable; please try again.');

        Http::assertNothingSent();
        self::assertSame(0, MatchCommentary::where('fixture_id', $fixture->id)->count());
    }

    public function test_upstream_failure_returns_503_and_does_not_persist(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'rate limit'], 429),
        ]);

        $fixture = $this->firstPlayedFixture();

        $this->getJson("/api/fixtures/{$fixture->id}/commentary")
            ->assertStatus(503)
            ->assertJsonPath('message', 'Commentary service unavailable; please try again.');

        self::assertSame(0, MatchCommentary::where('fixture_id', $fixture->id)->count());
    }

    private function firstPlayedFixture(): Fixture
    {
        app(LeagueService::class)->nextWeek(Season::current());

        return Fixture::where('played', true)
            ->orderBy('id')
            ->with(['homeTeam', 'awayTeam'])
            ->firstOrFail();
    }
}
