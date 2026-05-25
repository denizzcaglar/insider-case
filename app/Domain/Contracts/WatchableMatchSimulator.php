<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

use App\Domain\ValueObjects\MatchResultWithEvents;
use App\Domain\ValueObjects\TeamStrength;

// Extends MatchSimulator with an event stream.
interface WatchableMatchSimulator extends MatchSimulator
{
    public function simulateWithEvents(TeamStrength $home, TeamStrength $away): MatchResultWithEvents;
}
