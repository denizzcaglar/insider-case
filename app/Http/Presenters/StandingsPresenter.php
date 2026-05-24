<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use App\Domain\ValueObjects\StandingsTable;

final class StandingsPresenter
{
    /**
     * Serialize a {@see StandingsTable} as a positional array of rows.
     *
     * @return list<array<string, mixed>>
     */
    public static function present(StandingsTable $table): array
    {
        $out = [];
        foreach ($table->rows() as $i => $row) {
            $out[] = [
                'position' => $i + 1,
                'team' => [
                    'id' => $row->teamId,
                    'name' => $row->teamName,
                    'short_name' => $row->teamShortName,
                ],
                'played' => $row->played,
                'won' => $row->won,
                'drawn' => $row->drawn,
                'lost' => $row->lost,
                'goals_for' => $row->goalsFor,
                'goals_against' => $row->goalsAgainst,
                'goal_difference' => $row->goalDifference(),
                'points' => $row->points(),
            ];
        }

        return $out;
    }
}
