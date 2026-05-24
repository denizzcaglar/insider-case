<?php

declare(strict_types=1);

namespace App\Services\Prediction;

/**
 * Per-team multiplicative form factors (attack, defense) derived from the
 * current season's played fixtures via exponentially weighted moving average.
 *
 * Form starts at 1.0 (neutral) and is updated chronologically. A team that
 * has overperformed against its prior gets form_attack > 1.0; under-conceded
 * gets form_defense > 1.0.
 */
final class CurrentFormTracker
{
    public const AVG_ATTACK = HistoricalStrengthFitter::AVG_ATTACK;
    public const AVG_DEFENSE = HistoricalStrengthFitter::AVG_DEFENSE;
    private const BASELINE_LAMBDA = 1.35;

    /**
     * Additive smoothing constant for goal ratios. A clean sheet (observed = 0)
     * would otherwise produce a runaway ratio with a tiny denominator. With this
     * smoothing, a 0-goal match becomes (expected + λ)/(0 + λ) instead of
     * expected/0.05.
     */
    private const LAPLACE_LAMBDA = 1.0;

    public function __construct(
        private readonly float $ewmaAlpha = 0.25,
    ) {
    }

    /**
     * @param  array<int, array{attack: float, defense: float}>  $priorStrengths  Keyed by team_id.
     * @param  array<int, float>  $homeAdvantageByTeam  Keyed by team_id; the home boost factor used when the team plays at home.
     * @param  list<array{home_team_id:int, away_team_id:int, home_goals:int, away_goals:int, played_at:string|null}>  $matchesChronological
     * @return array<int, array{attack: float, defense: float}>
     */
    public function track(array $priorStrengths, array $homeAdvantageByTeam, array $matchesChronological): array
    {
        $form = [];
        foreach (array_keys($priorStrengths) as $id) {
            $form[$id] = ['attack' => 1.0, 'defense' => 1.0];
        }

        foreach ($matchesChronological as $m) {
            $h = $m['home_team_id'];
            $a = $m['away_team_id'];

            if (! isset($priorStrengths[$h], $priorStrengths[$a])) {
                continue;
            }

            $hf = max(0.5, (float) ($homeAdvantageByTeam[$h] ?? 1.0));

            // Expected goals must come from the EFFECTIVE strengths the simulator
            // actually used for this match (prior * current form), not the bare prior.
            // Otherwise the form factor measures itself: simulated goals scale with
            // form, ratio stays above 1, form keeps growing — positive feedback loop.
            $effHomeAttack = $priorStrengths[$h]['attack'] * $form[$h]['attack'];
            $effHomeDefense = $priorStrengths[$h]['defense'] * $form[$h]['defense'];
            $effAwayAttack = $priorStrengths[$a]['attack'] * $form[$a]['attack'];
            $effAwayDefense = $priorStrengths[$a]['defense'] * $form[$a]['defense'];

            $expHomeGoals = self::BASELINE_LAMBDA
                * ($effHomeAttack / self::AVG_ATTACK)
                * (self::AVG_DEFENSE / max(1.0, $effAwayDefense))
                * $hf;

            $expAwayGoals = self::BASELINE_LAMBDA
                * ($effAwayAttack / self::AVG_ATTACK)
                * (self::AVG_DEFENSE / max(1.0, $effHomeDefense));

            $expHomeGoals = max(0.1, $expHomeGoals);
            $expAwayGoals = max(0.1, $expAwayGoals);

            $homeGoalsSmoothed = $m['home_goals'] + self::LAPLACE_LAMBDA;
            $awayGoalsSmoothed = $m['away_goals'] + self::LAPLACE_LAMBDA;
            $expHomeSmoothed = $expHomeGoals + self::LAPLACE_LAMBDA;
            $expAwaySmoothed = $expAwayGoals + self::LAPLACE_LAMBDA;

            $ratioHomeAttack = $homeGoalsSmoothed / $expHomeSmoothed;
            $ratioAwayAttack = $awayGoalsSmoothed / $expAwaySmoothed;

            // Defense ratio: lower goals-conceded vs expected -> better defense (>1).
            $ratioHomeDefense = $expAwaySmoothed / $awayGoalsSmoothed;
            $ratioAwayDefense = $expHomeSmoothed / $homeGoalsSmoothed;

            $form[$h]['attack'] = $this->clamp($this->ewmaAlpha * $ratioHomeAttack + (1 - $this->ewmaAlpha) * $form[$h]['attack']);
            $form[$h]['defense'] = $this->clamp($this->ewmaAlpha * $ratioHomeDefense + (1 - $this->ewmaAlpha) * $form[$h]['defense']);
            $form[$a]['attack'] = $this->clamp($this->ewmaAlpha * $ratioAwayAttack + (1 - $this->ewmaAlpha) * $form[$a]['attack']);
            $form[$a]['defense'] = $this->clamp($this->ewmaAlpha * $ratioAwayDefense + (1 - $this->ewmaAlpha) * $form[$a]['defense']);
        }

        return $form;
    }

    /**
     * Clamp form factors to a sane band. Applied per-update so unclamped values
     * never leak into the next iteration's expected-goals calculation.
     */
    private function clamp(float $value): float
    {
        return max(0.5, min(1.8, $value));
    }
}
