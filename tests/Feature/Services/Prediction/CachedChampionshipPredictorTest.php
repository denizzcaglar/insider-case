<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Prediction;

use App\Domain\Contracts\ChampionshipPredictor;
use App\Domain\ValueObjects\PredictionResult;
use App\Models\Season;
use App\Services\League\LeagueService;
use App\Services\Prediction\CachedChampionshipPredictor;
use App\Services\Prediction\PredictionCacheStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class CachedChampionshipPredictorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_seed_pinned_call_is_cached_on_second_invocation(): void
    {
        Cache::flush();
        $inner = new SpyPredictor();
        $store = $this->app->make(PredictionCacheStore::class);
        $cached = new CachedChampionshipPredictor($inner, $store);

        $cached->predict(Season::current(), 100, 'fixed-seed');
        $cached->predict(Season::current(), 100, 'fixed-seed');

        self::assertSame(1, $inner->calls, 'Second call should hit the cache, not the inner predictor.');
    }

    public function test_unseeded_call_is_never_cached(): void
    {
        Cache::flush();
        $inner = new SpyPredictor();
        $store = $this->app->make(PredictionCacheStore::class);
        $cached = new CachedChampionshipPredictor($inner, $store);

        $cached->predict(Season::current(), 100, null);
        $cached->predict(Season::current(), 100, null);

        self::assertSame(2, $inner->calls, 'Unseeded calls must bypass cache (non-deterministic results).');
    }

    public function test_different_seed_or_iterations_produces_a_separate_cache_key(): void
    {
        Cache::flush();
        $inner = new SpyPredictor();
        $store = $this->app->make(PredictionCacheStore::class);
        $cached = new CachedChampionshipPredictor($inner, $store);

        $cached->predict(Season::current(), 100, 'seed-a');
        $cached->predict(Season::current(), 100, 'seed-b');
        $cached->predict(Season::current(), 200, 'seed-a');

        self::assertSame(3, $inner->calls);
    }

    public function test_next_week_busts_the_cache(): void
    {
        Cache::flush();
        $inner = new SpyPredictor();
        $store = $this->app->make(PredictionCacheStore::class);
        $cached = new CachedChampionshipPredictor($inner, $store);

        // Prime the cache.
        $cached->predict(Season::current(), 100, 'seed-a');
        self::assertSame(1, $inner->calls);

        // Mutate season state via the real service.
        $this->app->make(LeagueService::class)->nextWeek(Season::current());

        // Same key would normally hit cache, but the bust + hash change force a recompute.
        $cached->predict(Season::current(), 100, 'seed-a');
        self::assertSame(2, $inner->calls);
    }

    public function test_edit_result_busts_the_cache(): void
    {
        Cache::flush();
        $inner = new SpyPredictor();
        $store = $this->app->make(PredictionCacheStore::class);
        $cached = new CachedChampionshipPredictor($inner, $store);
        $league = $this->app->make(LeagueService::class);

        $league->nextWeek(Season::current());
        $fixture = Season::current()->fixtures()->where('played', true)->first();

        $cached->predict(Season::current(), 100, 'seed-a');
        self::assertSame(1, $inner->calls);

        $league->editResult($fixture, 9, 0);

        $cached->predict(Season::current(), 100, 'seed-a');
        self::assertSame(2, $inner->calls);
    }

    public function test_reset_busts_the_cache(): void
    {
        Cache::flush();
        $inner = new SpyPredictor();
        $store = $this->app->make(PredictionCacheStore::class);
        $cached = new CachedChampionshipPredictor($inner, $store);
        $league = $this->app->make(LeagueService::class);

        $cached->predict(Season::current(), 100, 'seed-a');
        self::assertSame(1, $inner->calls);

        $league->reset(Season::current(), 'fresh');

        $cached->predict(Season::current(), 100, 'seed-a');
        self::assertSame(2, $inner->calls);
    }
}

/**
 * Minimal stub that records how many times predict() was actually invoked.
 */
final class SpyPredictor implements ChampionshipPredictor
{
    public int $calls = 0;

    public function predict(Season $season, int $iterations, ?string $seed = null): PredictionResult
    {
        $this->calls++;

        return new PredictionResult([1 => 100.0, 2 => 0.0, 3 => 0.0, 4 => 0.0], $iterations, $seed ?? 'auto');
    }
}
