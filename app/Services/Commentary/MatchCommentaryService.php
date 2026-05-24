<?php

declare(strict_types=1);

namespace App\Services\Commentary;

use App\Domain\ValueObjects\PlayedFixture;
use App\Domain\ValueObjects\Score;
use App\Domain\ValueObjects\TeamStrength;
use App\Models\Fixture;
use App\Models\MatchCommentary;
use App\Models\Team;
use App\Services\Standings\StandingsCalculator;
use InvalidArgumentException;

/**
 * Orchestrates lazy generation and caching of fixture commentaries.
 *
 * Public contract: pass a Fixture, get back commentary text. Internally:
 * - hits the local cache table first;
 * - on miss (or on stale row whose score no longer matches the fixture's),
 *   delegates to {@see CommentaryGenerator}, persists the result, returns it;
 * - never persists when generation fails.
 *
 * Standings before/after the match are computed on the fly from the season's
 * other played fixtures, using the existing {@see StandingsCalculator}.
 */
final class MatchCommentaryService
{
    public function __construct(
        private readonly CommentaryGenerator $generator,
        private readonly StandingsCalculator $standingsCalculator = new StandingsCalculator(),
    ) {
    }

    public function for(Fixture $fixture): string
    {
        if (! $fixture->played) {
            throw new InvalidArgumentException("Fixture {$fixture->id} has not been played yet.");
        }

        $cached = MatchCommentary::query()->where('fixture_id', $fixture->id)->first();
        if ($cached instanceof MatchCommentary
            && (int) $cached->home_goals === (int) $fixture->home_goals
            && (int) $cached->away_goals === (int) $fixture->away_goals) {
            return $cached->content;
        }

        $fixture->loadMissing(['homeTeam', 'awayTeam']);

        [$before, $after] = $this->standingsAround($fixture);
        $content = $this->generator->generate($fixture, $before, $after);

        MatchCommentary::updateOrCreate(
            ['fixture_id' => $fixture->id],
            [
                'home_goals' => (int) $fixture->home_goals,
                'away_goals' => (int) $fixture->away_goals,
                'content' => $content,
            ],
        );

        return $content;
    }

    /**
     * Compute the standings table immediately before and immediately after the
     * given fixture, considering only played fixtures of the same season.
     *
     * @return array{0: \App\Domain\ValueObjects\StandingsTable, 1: \App\Domain\ValueObjects\StandingsTable}
     */
    private function standingsAround(Fixture $fixture): array
    {
        $teams = Team::orderBy('id')
            ->get()
            ->map(fn (Team $t) => TeamStrength::fromTeam($t))
            ->all();

        $allPlayedThisSeason = Fixture::query()
            ->where('season_id', $fixture->season_id)
            ->where('played', true)
            ->orderBy('week')
            ->orderBy('id')
            ->get(['id', 'week', 'home_team_id', 'away_team_id', 'home_goals', 'away_goals']);

        $beforeList = [];
        $afterList = [];
        foreach ($allPlayedThisSeason as $f) {
            $vo = new PlayedFixture(
                (int) $f->home_team_id,
                (int) $f->away_team_id,
                new Score((int) $f->home_goals, (int) $f->away_goals),
            );

            if ($f->id < $fixture->id) {
                $beforeList[] = $vo;
                $afterList[] = $vo;
            } elseif ($f->id === $fixture->id) {
                $afterList[] = $vo;
            }
        }

        return [
            $this->standingsCalculator->calculate($teams, $beforeList),
            $this->standingsCalculator->calculate($teams, $afterList),
        ];
    }
}
