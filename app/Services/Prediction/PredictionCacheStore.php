<?php

declare(strict_types=1);

namespace App\Services\Prediction;

use App\Domain\ValueObjects\PredictionResult;
use App\Models\Fixture;
use App\Models\Season;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Owns the prediction cache key strategy and per-season key registry.
 *
 * Cache key:
 *     predictions:{season_id}:{played_fixtures_hash}:{historical_hash}:{iterations}:{seed}
 *
 * The played-fixtures hash makes the key naturally invalidate when state changes;
 * the historical hash protects against cross-seeding contamination if a long-lived
 * cache store survives a re-seed. The per-season key registry lets us actively
 * forget the old keys on every mutation.
 */
final class PredictionCacheStore
{
    public const TTL_SECONDS = 3600;

    public function __construct(private readonly CacheRepository $cache)
    {
    }

    /**
     * @param  Closure(): PredictionResult  $compute
     */
    public function remember(
        int $seasonId,
        string $playedFixturesHash,
        string $historicalHash,
        int $iterations,
        string $seed,
        Closure $compute,
    ): PredictionResult {
        $key = $this->key($seasonId, $playedFixturesHash, $historicalHash, $iterations, $seed);

        if (($hit = $this->cache->get($key)) instanceof PredictionResult) {
            return $hit;
        }

        $result = $compute();

        $this->cache->put($key, $result, self::TTL_SECONDS);
        $this->registerKey($seasonId, $key);

        return $result;
    }

    /**
     * Forget every prediction cache entry for the given season.
     */
    public function bustForSeason(int $seasonId): void
    {
        $registryKey = $this->registryKey($seasonId);
        $keys = $this->cache->get($registryKey, []);

        foreach ($keys as $key) {
            $this->cache->forget($key);
        }

        $this->cache->forget($registryKey);
    }

    /**
     * MD5 of the season's played-fixture results, ordered by fixture id.
     */
    public function playedFixturesHash(Season $season): string
    {
        $fingerprint = Fixture::query()
            ->where('season_id', $season->id)
            ->where('played', true)
            ->orderBy('id')
            ->get(['id', 'home_goals', 'away_goals'])
            ->map(static fn (Fixture $f) => "{$f->id}:{$f->home_goals}:{$f->away_goals}")
            ->implode(',');

        return md5($fingerprint);
    }

    /**
     * MD5 of every historical season's played fixtures. Effectively constant at
     * runtime because historical seasons are read-only; included to prevent
     * stale cache entries surviving a re-seed.
     */
    public function historicalSeasonsHash(): string
    {
        $fingerprint = Fixture::query()
            ->whereHas('season', fn ($q) => $q->where('is_historical', true))
            ->orderBy('season_id')
            ->orderBy('id')
            ->get(['season_id', 'id', 'home_goals', 'away_goals'])
            ->map(static fn (Fixture $f) => "{$f->season_id}:{$f->id}:{$f->home_goals}:{$f->away_goals}")
            ->implode(',');

        return md5($fingerprint);
    }

    private function key(int $seasonId, string $hash, string $historicalHash, int $iterations, string $seed): string
    {
        return "predictions:{$seasonId}:{$hash}:{$historicalHash}:{$iterations}:{$seed}";
    }

    private function registryKey(int $seasonId): string
    {
        return "predictions:registry:{$seasonId}";
    }

    private function registerKey(int $seasonId, string $key): void
    {
        $registryKey = $this->registryKey($seasonId);
        $keys = $this->cache->get($registryKey, []);

        if (! in_array($key, $keys, true)) {
            $keys[] = $key;
            $this->cache->put($registryKey, $keys, self::TTL_SECONDS * 2);
        }
    }
}
