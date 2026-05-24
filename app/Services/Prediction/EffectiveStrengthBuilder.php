<?php

declare(strict_types=1);

namespace App\Services\Prediction;

use App\Domain\ValueObjects\StrengthBreakdown;
use App\Domain\ValueObjects\TeamStrength;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;

/**
 * Composes the historical fitter and the current-season form tracker into the
 * `TeamStrength[]` array that the predictor feeds to the Poisson simulator.
 *
 * Also produces a parallel `StrengthBreakdown[]` so the API can surface the
 * model inputs (seed / prior / form / effective) for each team.
 */
final class EffectiveStrengthBuilder
{
    public function __construct(
        private readonly HistoricalStrengthFitter $fitter,
        private readonly CurrentFormTracker $formTracker,
    ) {
    }

    /**
     * @return array{strengths: array<int, TeamStrength>, breakdowns: array<int, StrengthBreakdown>}
     */
    public function build(Season $currentSeason): array
    {
        $teams = Team::orderBy('id')->get();

        $seedStrengths = [];
        $homeAdvantage = [];
        foreach ($teams as $team) {
            $seedStrengths[$team->id] = TeamStrength::fromTeam($team);
            $homeAdvantage[$team->id] = (float) $team->home_advantage;
        }

        $historicalMatches = $this->loadHistoricalMatches($homeAdvantage);
        $priors = $this->fitter->fit($seedStrengths, $historicalMatches);

        $currentPlayed = $this->loadCurrentPlayedFixtures($currentSeason);
        $form = $this->formTracker->track($priors, $homeAdvantage, $currentPlayed);

        $strengths = [];
        $breakdowns = [];
        foreach ($teams as $team) {
            $id = $team->id;
            $effectiveAttack = $priors[$id]['attack'] * $form[$id]['attack'];
            $effectiveDefense = $priors[$id]['defense'] * $form[$id]['defense'];

            $strengths[$id] = new TeamStrength(
                id: $id,
                name: $team->name,
                shortName: $team->short_name,
                attack: max(1, (int) round($effectiveAttack)),
                defense: max(1, (int) round($effectiveDefense)),
                homeAdvantage: (float) $team->home_advantage,
            );

            $breakdowns[$id] = new StrengthBreakdown(
                teamId: $id,
                name: $team->name,
                shortName: $team->short_name,
                seedAttack: (float) $team->attack,
                seedDefense: (float) $team->defense,
                priorAttack: $priors[$id]['attack'],
                priorDefense: $priors[$id]['defense'],
                formAttack: $form[$id]['attack'],
                formDefense: $form[$id]['defense'],
                effectiveAttack: $effectiveAttack,
                effectiveDefense: $effectiveDefense,
            );
        }

        return ['strengths' => $strengths, 'breakdowns' => $breakdowns];
    }

    /**
     * @param  array<int, float>  $homeAdvantage  Keyed by team_id.
     * @return list<array{home_team_id:int, away_team_id:int, home_goals:int, away_goals:int, played_at:string|null, home_advantage:float}>
     */
    private function loadHistoricalMatches(array $homeAdvantage): array
    {
        return Fixture::query()
            ->whereHas('season', fn ($q) => $q->where('is_historical', true))
            ->where('played', true)
            ->orderBy('simulated_at')
            ->get(['home_team_id', 'away_team_id', 'home_goals', 'away_goals', 'simulated_at'])
            ->map(fn (Fixture $f) => [
                'home_team_id' => (int) $f->home_team_id,
                'away_team_id' => (int) $f->away_team_id,
                'home_goals' => (int) $f->home_goals,
                'away_goals' => (int) $f->away_goals,
                'played_at' => $f->simulated_at?->toDateString(),
                'home_advantage' => $homeAdvantage[(int) $f->home_team_id] ?? 1.0,
            ])
            ->all();
    }

    /**
     * @return list<array{home_team_id:int, away_team_id:int, home_goals:int, away_goals:int, played_at:string|null}>
     */
    private function loadCurrentPlayedFixtures(Season $season): array
    {
        return Fixture::query()
            ->where('season_id', $season->id)
            ->where('played', true)
            ->orderBy('week')
            ->orderBy('id')
            ->get(['home_team_id', 'away_team_id', 'home_goals', 'away_goals', 'simulated_at'])
            ->map(fn (Fixture $f) => [
                'home_team_id' => (int) $f->home_team_id,
                'away_team_id' => (int) $f->away_team_id,
                'home_goals' => (int) $f->home_goals,
                'away_goals' => (int) $f->away_goals,
                'played_at' => $f->simulated_at?->toDateString(),
            ])
            ->all();
    }
}
