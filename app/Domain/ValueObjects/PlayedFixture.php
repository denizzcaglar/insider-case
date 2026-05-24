<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

final readonly class PlayedFixture
{
    public function __construct(
        public int $homeTeamId,
        public int $awayTeamId,
        public Score $score,
    ) {
    }
}
