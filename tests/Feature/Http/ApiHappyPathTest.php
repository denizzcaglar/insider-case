<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Fixture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApiHappyPathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_initial_standings_show_four_zero_rows_and_not_complete(): void
    {
        $response = $this->getJson('/api/standings');

        $response->assertOk()
            ->assertJsonCount(4, 'standings')
            ->assertJsonPath('season.is_complete', false)
            ->assertJsonPath('season.fixtures_total', 0)
            ->assertJsonPath('standings.0.points', 0);
    }

    public function test_next_week_endpoint_plays_two_matches_and_advances_state(): void
    {
        $response = $this->postJson('/api/weeks/next');

        $response->assertOk()
            ->assertJsonPath('week_played', 1)
            ->assertJsonCount(2, 'results')
            ->assertJsonPath('season.fixtures_played', 2)
            ->assertJsonPath('season.current_week', 2);
    }

    public function test_play_all_completes_the_season(): void
    {
        $response = $this->postJson('/api/weeks/play-all');

        $response->assertOk()
            ->assertJsonCount(6, 'weeks_played')
            ->assertJsonPath('season.is_complete', true)
            ->assertJsonPath('season.fixtures_played', 12)
            ->assertJsonCount(4, 'final_standings');

        // Final winner should have positive points; last place should be the weakest.
        $standings = $response->json('final_standings');
        self::assertGreaterThanOrEqual(0, $standings[0]['points']);
        self::assertGreaterThanOrEqual($standings[3]['points'], $standings[0]['points']);
    }

    public function test_predictions_endpoint_returns_four_rows_summing_to_one_hundred(): void
    {
        $response = $this->getJson('/api/predictions?iterations=500&seed=http-test');

        $response->assertOk()
            ->assertJsonCount(4, 'predictions')
            ->assertJsonPath('seed', 'http-test')
            ->assertJsonPath('iterations', 500);

        $sum = array_sum(array_column($response->json('predictions'), 'title_probability'));
        self::assertEqualsWithDelta(100.0, $sum, 0.1);
    }

    public function test_predictions_default_iterations_when_not_supplied(): void
    {
        $response = $this->getJson('/api/predictions?seed=defaults');

        $response->assertOk()->assertJsonPath('iterations', 10000);
    }

    public function test_predictions_validate_iterations_bounds(): void
    {
        $this->getJson('/api/predictions?iterations=0')->assertStatus(422);
        $this->getJson('/api/predictions?iterations=200000')->assertStatus(422);
    }

    public function test_patch_fixture_edits_result_and_returns_fresh_standings(): void
    {
        $this->postJson('/api/weeks/play-all');
        $fixture = Fixture::query()
            ->whereHas('season', fn ($q) => $q->where('is_historical', false))
            ->orderBy('id')
            ->first();

        $response = $this->patchJson("/api/fixtures/{$fixture->id}", [
            'home_goals' => 9,
            'away_goals' => 0,
        ]);

        $response->assertOk()
            ->assertJsonPath('fixture.home_goals', 9)
            ->assertJsonPath('fixture.away_goals', 0)
            ->assertJsonCount(4, 'standings');
    }

    public function test_patch_fixture_validates_input(): void
    {
        $this->postJson('/api/weeks/next');
        $fixture = Fixture::played()
            ->whereHas('season', fn ($q) => $q->where('is_historical', false))
            ->first();

        $this->patchJson("/api/fixtures/{$fixture->id}", [
            'home_goals' => -1,
            'away_goals' => 0,
        ])->assertStatus(422);

        $this->patchJson("/api/fixtures/{$fixture->id}", [
            'home_goals' => 1,
        ])->assertStatus(422);
    }

    public function test_league_reset_clears_results_and_keeps_schedule(): void
    {
        $this->postJson('/api/weeks/play-all');

        $response = $this->postJson('/api/league/reset', ['seed' => 'after-reset']);

        $response->assertOk()
            ->assertJsonPath('season.fixtures_total', 12)
            ->assertJsonPath('season.fixtures_played', 0)
            ->assertJsonPath('season.rng_seed', 'after-reset');
    }

    public function test_next_week_after_complete_returns_409(): void
    {
        $this->postJson('/api/weeks/play-all');

        $this->postJson('/api/weeks/next')->assertStatus(409);
    }

    public function test_fixtures_endpoint_groups_by_week(): void
    {
        $this->postJson('/api/weeks/play-all');

        $response = $this->getJson('/api/fixtures');

        $response->assertOk();
        $byWeek = $response->json('fixtures_by_week');
        self::assertSame([1, 2, 3, 4, 5, 6], array_keys($byWeek));
        foreach ($byWeek as $weekFixtures) {
            self::assertCount(2, $weekFixtures);
        }
    }
}
