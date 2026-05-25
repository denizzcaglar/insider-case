<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

// Composes engine + RNG so they share a seed.
interface MatchSimulatorFactory
{
    public function forWatching(?string $seed = null): WatchableMatchSimulator;
}
