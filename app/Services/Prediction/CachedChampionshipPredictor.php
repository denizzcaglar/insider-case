<?php

declare(strict_types=1);

namespace App\Services\Prediction;

use App\Domain\Contracts\ChampionshipPredictor;
use App\Domain\ValueObjects\PredictionResult;
use App\Models\Season;

/**
 * Caching decorator for {@see ChampionshipPredictor}.
 *
 * Only seed-pinned calls are cached. A null seed implies "fresh randomness per call"
 * (the controller's default behaviour when no `?seed=` query param is given), and
 * caching those would silently return identical results across requests.
 *
 * Cache invalidation is driven by two mechanisms working together:
 *
 *   1. Implicit: the cache key contains a hash of played fixtures AND a hash of
 *      historical seasons' state, so any change yields a fresh key.
 *
 *   2. Explicit: {@see PredictionCacheStore::bustForSeason()} is called by the
 *      league service on every mutation of a simulated season, which actively
 *      forgets the old keys instead of waiting for TTL.
 */
final class CachedChampionshipPredictor implements ChampionshipPredictor
{
    public function __construct(
        private readonly ChampionshipPredictor $inner,
        private readonly PredictionCacheStore $store,
    ) {
    }

    public function predict(Season $season, int $iterations, ?string $seed = null): PredictionResult
    {
        if ($seed === null) {
            return $this->inner->predict($season, $iterations, $seed);
        }

        return $this->store->remember(
            seasonId: (int) $season->id,
            playedFixturesHash: $this->store->playedFixturesHash($season),
            historicalHash: $this->store->historicalSeasonsHash(),
            iterations: $iterations,
            seed: $seed,
            compute: fn () => $this->inner->predict($season, $iterations, $seed),
        );
    }
}
