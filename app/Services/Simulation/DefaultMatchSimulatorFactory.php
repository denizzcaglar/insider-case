<?php

declare(strict_types=1);

namespace App\Services\Simulation;

use App\Domain\Contracts\MatchSimulatorFactory;
use App\Domain\Contracts\WatchableMatchSimulator;
use App\Support\SeededRng;

final class DefaultMatchSimulatorFactory implements MatchSimulatorFactory
{
    public function forWatching(?string $seed = null): WatchableMatchSimulator
    {
        $rng = new SeededRng($seed);

        return new TickMatchSimulator($rng, new PlayerDecisionEngine($rng));
    }
}
