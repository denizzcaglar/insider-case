<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

use App\Domain\ValueObjects\MatchResult;
use App\Domain\ValueObjects\TeamStrength;

interface MatchSimulator
{
    public function simulate(TeamStrength $home, TeamStrength $away): MatchResult;
}
