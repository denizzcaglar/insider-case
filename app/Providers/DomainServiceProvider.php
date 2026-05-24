<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Contracts\ChampionshipPredictor;
use App\Domain\Contracts\MatchSimulator;
use App\Services\Prediction\CachedChampionshipPredictor;
use App\Services\Prediction\CurrentFormTracker;
use App\Services\Prediction\EffectiveStrengthBuilder;
use App\Services\Prediction\HistoricalStrengthFitter;
use App\Services\Prediction\MonteCarloChampionshipPredictor;
use App\Services\Prediction\PredictionCacheStore;
use App\Services\Simulation\StatisticalMatchSimulator;
use App\Services\Standings\StandingsCalculator;
use App\Support\SeededRng;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;

/**
 * Binds domain contracts to their concrete implementations.
 *
 * The fast {@see StatisticalMatchSimulator} is the default {@see MatchSimulator}.
 * The predictor receives a seedable factory that produces the same fast engine,
 * wrapped in a {@see CachedChampionshipPredictor} for per-(season, state, seed)
 * result caching. Team strengths come from {@see EffectiveStrengthBuilder},
 * which composes the historical fit and the current-season form tracker.
 */
final class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MatchSimulator::class, fn () => new StatisticalMatchSimulator(
            new SeededRng(),
        ));

        $this->app->singleton(HistoricalStrengthFitter::class, fn () => new HistoricalStrengthFitter());
        $this->app->singleton(CurrentFormTracker::class, fn () => new CurrentFormTracker());

        $this->app->singleton(
            EffectiveStrengthBuilder::class,
            fn ($app) => new EffectiveStrengthBuilder(
                $app->make(HistoricalStrengthFitter::class),
                $app->make(CurrentFormTracker::class),
            ),
        );

        $this->app->singleton(
            PredictionCacheStore::class,
            fn ($app) => new PredictionCacheStore($app->make(CacheRepository::class)),
        );

        $this->app->singleton(
            ChampionshipPredictor::class,
            fn ($app) => new CachedChampionshipPredictor(
                inner: new MonteCarloChampionshipPredictor(
                    simulatorFactory: static fn (string $seed): MatchSimulator => new StatisticalMatchSimulator(
                        new SeededRng($seed),
                    ),
                    strengthBuilder: $app->make(EffectiveStrengthBuilder::class),
                    calculator: new StandingsCalculator(),
                ),
                store: $app->make(PredictionCacheStore::class),
            ),
        );
    }
}
