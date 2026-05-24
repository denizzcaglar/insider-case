<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Commentary;

use App\Domain\ValueObjects\StandingRow;
use App\Domain\ValueObjects\StandingsTable;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use App\Services\Commentary\CommentaryGenerator;
use App\Services\Commentary\Exceptions\CommentaryGenerationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CommentaryGeneratorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['commentary.api_key' => 'test-key', 'commentary.model' => 'gemini-2.5-flash']);
    }

    public function test_happy_path_returns_trimmed_text_and_calls_expected_endpoint(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => "  City dominated. Liverpool struggled.  \n"]]]],
                ],
            ], 200),
        ]);

        $fixture = $this->makeFixture(homeShort: 'MCI', awayShort: 'LIV', homeGoals: 3, awayGoals: 1, week: 4);

        $result = app(CommentaryGenerator::class)->generate(
            $fixture,
            $this->emptyStandings(),
            $this->emptyStandings(),
        );

        self::assertSame('City dominated. Liverpool struggled.', $result);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'gemini-2.5-flash:generateContent')
                && str_contains($request->url(), 'key=test-key')
                && str_contains($request['contents'][0]['parts'][0]['text'], 'Manchester City 3 - 1 Liverpool')
                && str_contains($request['contents'][0]['parts'][0]['text'], 'Week: 4 of 6');
        });
    }

    public function test_missing_api_key_throws_configuration_error(): void
    {
        config(['commentary.api_key' => '']);
        Http::fake();

        $fixture = $this->makeFixture();

        $this->expectException(CommentaryGenerationException::class);
        $this->expectExceptionMessage('Gemini API key is not configured.');

        app(CommentaryGenerator::class)->generate($fixture, $this->emptyStandings(), $this->emptyStandings());

        Http::assertNothingSent();
    }

    public function test_non_2xx_response_throws(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'quota'], 429),
        ]);

        $fixture = $this->makeFixture();

        $this->expectException(CommentaryGenerationException::class);
        app(CommentaryGenerator::class)->generate($fixture, $this->emptyStandings(), $this->emptyStandings());
    }

    public function test_empty_text_in_candidates_throws(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => '']]]]],
            ], 200),
        ]);

        $fixture = $this->makeFixture();

        $this->expectException(CommentaryGenerationException::class);
        app(CommentaryGenerator::class)->generate($fixture, $this->emptyStandings(), $this->emptyStandings());
    }

    public function test_malformed_response_shape_throws(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['unexpected' => 'shape'], 200),
        ]);

        $fixture = $this->makeFixture();

        $this->expectException(CommentaryGenerationException::class);
        app(CommentaryGenerator::class)->generate($fixture, $this->emptyStandings(), $this->emptyStandings());
    }

    private function makeFixture(
        string $homeShort = 'MCI',
        string $awayShort = 'LIV',
        int $homeGoals = 1,
        int $awayGoals = 0,
        int $week = 1,
    ): Fixture {
        $season = Season::current();
        $home = Team::where('short_name', $homeShort)->firstOrFail();
        $away = Team::where('short_name', $awayShort)->firstOrFail();

        $fixture = Fixture::create([
            'season_id' => $season->id,
            'week' => $week,
            'home_team_id' => $home->id,
            'away_team_id' => $away->id,
            'played' => true,
            'home_goals' => $homeGoals,
            'away_goals' => $awayGoals,
            'simulated_at' => now(),
        ]);

        return $fixture->load(['homeTeam', 'awayTeam']);
    }

    private function emptyStandings(): StandingsTable
    {
        $rows = Team::orderBy('id')
            ->get()
            ->map(fn (Team $t) => new StandingRow(
                teamId: (int) $t->id,
                teamName: $t->name,
                teamShortName: $t->short_name,
            ))
            ->all();

        return new StandingsTable(...$rows);
    }
}
