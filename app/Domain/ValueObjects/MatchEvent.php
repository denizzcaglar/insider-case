<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

final readonly class MatchEvent
{
    public const TYPE_KICKOFF = 'kickoff';
    public const TYPE_HALFTIME = 'halftime';
    public const TYPE_FULLTIME = 'fulltime';
    public const TYPE_SHOT = 'shot';
    public const TYPE_SAVE = 'save';
    public const TYPE_GOAL = 'goal';
    public const TYPE_PASS = 'pass';
    public const TYPE_DRIBBLE = 'dribble';
    public const TYPE_TURNOVER = 'turnover';

    /**
     * @param  array<string, mixed>  $detail
     */
    public function __construct(
        public int $second,
        public string $type,
        public ?int $teamId = null,
        public ?int $playerId = null,
        public array $detail = [],
    ) {
    }

    public function minute(): int
    {
        return intdiv($this->second, 60);
    }

    public function clock(): string
    {
        return sprintf('%02d:%02d', intdiv($this->second, 60), $this->second % 60);
    }
}
