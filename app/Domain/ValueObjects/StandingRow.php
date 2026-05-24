<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use InvalidArgumentException;

final class StandingRow
{
    public function __construct(
        public readonly int $teamId,
        public readonly string $teamName,
        public readonly string $teamShortName,
        public int $played = 0,
        public int $won = 0,
        public int $drawn = 0,
        public int $lost = 0,
        public int $goalsFor = 0,
        public int $goalsAgainst = 0,
    ) {
    }

    public function applyResult(int $goalsFor, int $goalsAgainst): void
    {
        if ($goalsFor < 0 || $goalsAgainst < 0) {
            throw new InvalidArgumentException('Goals cannot be negative.');
        }

        $this->played++;
        $this->goalsFor += $goalsFor;
        $this->goalsAgainst += $goalsAgainst;

        if ($goalsFor > $goalsAgainst) {
            $this->won++;
        } elseif ($goalsFor < $goalsAgainst) {
            $this->lost++;
        } else {
            $this->drawn++;
        }
    }

    public function points(): int
    {
        return $this->won * 3 + $this->drawn;
    }

    public function goalDifference(): int
    {
        return $this->goalsFor - $this->goalsAgainst;
    }
}
