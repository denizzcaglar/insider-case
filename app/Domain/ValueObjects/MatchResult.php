<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

final readonly class MatchResult
{
    public function __construct(public Score $score)
    {
    }
}
