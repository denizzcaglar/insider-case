<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use OutOfBoundsException;

final class StandingsTable
{
    /** @var array<int, StandingRow> Keyed by team_id for O(1) lookup. */
    private array $rowsByTeam;

    public function __construct(StandingRow ...$rows)
    {
        $this->rowsByTeam = [];
        foreach ($rows as $row) {
            $this->rowsByTeam[$row->teamId] = $row;
        }
    }

    public function rowFor(int $teamId): StandingRow
    {
        if (! isset($this->rowsByTeam[$teamId])) {
            throw new OutOfBoundsException("No standing row for team id {$teamId}.");
        }

        return $this->rowsByTeam[$teamId];
    }

    public function apply(int $homeTeamId, int $awayTeamId, Score $score): void
    {
        $this->rowFor($homeTeamId)->applyResult($score->home, $score->away);
        $this->rowFor($awayTeamId)->applyResult($score->away, $score->home);
    }

    /**
     * Sort rows in place using the supplied comparator.
     *
     * @param  callable(StandingRow, StandingRow): int  $comparator
     */
    public function rank(callable $comparator): void
    {
        $rows = array_values($this->rowsByTeam);
        usort($rows, $comparator);

        $this->rowsByTeam = [];
        foreach ($rows as $row) {
            $this->rowsByTeam[$row->teamId] = $row;
        }
    }

    /** @return list<StandingRow> Rows in current order. */
    public function rows(): array
    {
        return array_values($this->rowsByTeam);
    }

    public function leader(): StandingRow
    {
        $rows = $this->rows();
        if ($rows === []) {
            throw new OutOfBoundsException('Standings table is empty.');
        }

        return $rows[0];
    }

    public function clone(): self
    {
        $clones = [];
        foreach ($this->rowsByTeam as $row) {
            $clones[] = clone $row;
        }

        return new self(...$clones);
    }
}
