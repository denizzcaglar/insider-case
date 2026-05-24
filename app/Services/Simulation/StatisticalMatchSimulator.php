<?php

declare(strict_types=1);

namespace App\Services\Simulation;

use App\Domain\Contracts\MatchSimulator;
use App\Domain\Contracts\Rng;
use App\Domain\ValueObjects\MatchResult;
use App\Domain\ValueObjects\Score;
use App\Domain\ValueObjects\TeamStrength;

/**
 * Fast Poisson-based match simulator.
 *
 * Expected goals are derived from team attack/defense ratings and a home-advantage
 * multiplier; final goals are sampled from a Poisson distribution with that mean.
 * Output is fully deterministic for a fixed RNG seed.
 */
final class StatisticalMatchSimulator implements MatchSimulator
{
    public function __construct(
        private readonly Rng $rng,
        private readonly float $baseLambda = 1.35,
        private readonly float $avgAttack = 80.0,
        private readonly float $avgDefense = 80.0,
    ) {
    }

    public function simulate(TeamStrength $home, TeamStrength $away): MatchResult
    {
        $homeLambda = $this->baseLambda
            * ($home->attack / $this->avgAttack)
            * ($this->avgDefense / max(1, $away->defense))
            * $home->homeAdvantage;

        $awayLambda = $this->baseLambda
            * ($away->attack / $this->avgAttack)
            * ($this->avgDefense / max(1, $home->defense));

        return new MatchResult(new Score(
            $this->rng->poisson($homeLambda),
            $this->rng->poisson($awayLambda),
        ));
    }
}
