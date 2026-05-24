<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Commentary;

use App\Models\Fixture;
use App\Models\MatchCommentary;
use App\Models\Season;
use App\Services\Commentary\MatchCommentaryService;
use App\Services\League\LeagueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

final class MatchCommentaryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        config(['commentary.api_key' => 'test-key']);
    }

    public function test_first_call_hits_gemini_and_persists_a_row(): void
    {
        $this->fakeGemini('First-call commentary.');
        $fixture = $this->firstPlayedFixture();

        $text = app(MatchCommentaryService::class)->for($fixture);

        self::assertSame('First-call commentary.', $text);
        self::assertSame(1, MatchCommentary::where('fixture_id', $fixture->id)->count());
        Http::assertSentCount(1);
    }

    public function test_second_call_with_same_score_returns_cached_row_without_hitting_gemini(): void
    {
        $this->fakeGemini('Cached commentary.');
        $fixture = $this->firstPlayedFixture();

        $service = app(MatchCommentaryService::class);
        $first = $service->for($fixture);
        $second = $service->for($fixture);

        self::assertSame($first, $second);
        Http::assertSentCount(1);
    }

    public function test_edit_result_clears_the_cache_and_next_call_regenerates(): void
    {
        $this->fakeGemini('canned commentary');
        $fixture = $this->firstPlayedFixture();
        $service = app(MatchCommentaryService::class);

        $service->for($fixture);
        Http::assertSentCount(1);
        self::assertSame(1, MatchCommentary::where('fixture_id', $fixture->id)->count(), 'precondition: row cached after first call');

        app(LeagueService::class)->editResult($fixture, 9, 0);
        self::assertSame(0, MatchCommentary::where('fixture_id', $fixture->id)->count(), 'editResult must delete cached commentary');

        $fresh = Fixture::find($fixture->id);
        $service->for($fresh);

        Http::assertSentCount(2, 'second call after edit must hit Gemini again');
        self::assertSame(1, MatchCommentary::where('fixture_id', $fixture->id)->count(), 'row should be regenerated and persisted');
    }

    public function test_unplayed_fixture_throws(): void
    {
        $this->fakeGemini('Should not run.');
        $unplayed = $this->ensureUnplayedFixture();

        $this->expectException(InvalidArgumentException::class);

        app(MatchCommentaryService::class)->for($unplayed);

        Http::assertNothingSent();
    }

    private function fakeGemini(string $text): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => $text]]]],
                ],
            ], 200),
        ]);
    }

    private function firstPlayedFixture(): Fixture
    {
        // Play exactly one week so we have a real played fixture with realistic state.
        app(LeagueService::class)->nextWeek(Season::current());

        return Fixture::query()
            ->where('played', true)
            ->orderBy('id')
            ->with(['homeTeam', 'awayTeam'])
            ->firstOrFail();
    }

    private function ensureUnplayedFixture(): Fixture
    {
        // Make sure fixtures exist (lazy generated on first nextWeek) but pick an unplayed one.
        app(LeagueService::class)->reset(Season::current(), 'unplayed-seed');

        return Fixture::query()
            ->where('played', false)
            ->orderBy('id')
            ->with(['homeTeam', 'awayTeam'])
            ->firstOrFail();
    }
}
