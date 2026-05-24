<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use InvalidArgumentException;
use Stringable;

final readonly class Score implements Stringable
{
    public const HOME = 'home';
    public const AWAY = 'away';
    public const DRAW = 'draw';

    public function __construct(public int $home, public int $away)
    {
        if ($home < 0 || $away < 0) {
            throw new InvalidArgumentException('Goals cannot be negative.');
        }
    }

    public function winner(): string
    {
        return match (true) {
            $this->home > $this->away => self::HOME,
            $this->home < $this->away => self::AWAY,
            default => self::DRAW,
        };
    }

    public function goalDifference(): int
    {
        return $this->home - $this->away;
    }

    public function __toString(): string
    {
        return "{$this->home}-{$this->away}";
    }
}
