<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\Fixture;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HistoricalSeasonReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function historicalSeason(): Season
    {
        return Season::query()->where('is_historical', true)->orderBy('id')->firstOrFail();
    }

    public function test_post_weeks_next_is_rejected_for_historical_season(): void
    {
        $season = $this->historicalSeason();
        $response = $this->postJson('/api/weeks/next', ['season_id' => $season->id]);

        $response->assertStatus(422)
            ->assertJsonPath('season.id', $season->id);
    }

    public function test_post_play_all_is_rejected_for_historical_season(): void
    {
        $season = $this->historicalSeason();
        $response = $this->postJson('/api/weeks/play-all', ['season_id' => $season->id]);

        $response->assertStatus(422);
    }

    public function test_post_league_reset_is_rejected_for_historical_season(): void
    {
        $season = $this->historicalSeason();
        $response = $this->postJson('/api/league/reset', ['season_id' => $season->id]);

        $response->assertStatus(422);
    }

    public function test_patch_historical_fixture_is_rejected(): void
    {
        $fixture = Fixture::query()
            ->whereHas('season', fn ($q) => $q->where('is_historical', true))
            ->first();

        $response = $this->patchJson("/api/fixtures/{$fixture->id}", [
            'home_goals' => 0,
            'away_goals' => 0,
        ]);

        $response->assertStatus(422);
    }

    public function test_get_endpoints_work_against_historical_season(): void
    {
        $season = $this->historicalSeason();

        $this->getJson('/api/standings?season_id='.$season->id)
            ->assertOk()
            ->assertJsonPath('season.is_historical', true)
            ->assertJsonCount(4, 'standings');

        $this->getJson('/api/fixtures?season_id='.$season->id)
            ->assertOk()
            ->assertJsonPath('season.is_historical', true);
    }

    public function test_simulated_season_is_unaffected_by_middleware(): void
    {
        $simulated = Season::query()->where('is_historical', false)->orderBy('id')->firstOrFail();

        $this->postJson('/api/weeks/next', ['season_id' => $simulated->id])->assertOk();
    }
}
