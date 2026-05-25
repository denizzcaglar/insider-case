<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

final readonly class MatchResultWithEvents
{
    /**
     * @param  list<MatchEvent>  $events
     */
    public function __construct(
        public MatchResult $result,
        public array $events,
    ) {
    }
}
