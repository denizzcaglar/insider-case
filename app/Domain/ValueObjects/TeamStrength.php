<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Models\Team;

final readonly class TeamStrength
{
    public function __construct(
        public int $id,
        public string $name,
        public string $shortName,
        public int $attack,
        public int $defense,
        public float $homeAdvantage,
    ) {
    }

    public static function fromTeam(Team $team): self
    {
        return new self(
            id: $team->id,
            name: $team->name,
            shortName: $team->short_name,
            attack: $team->attack,
            defense: $team->defense,
            homeAdvantage: (float) $team->home_advantage,
        );
    }
}
