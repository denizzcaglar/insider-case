<?php

declare(strict_types=1);

namespace App\Services\Prediction;

use App\Domain\ValueObjects\TeamStrength;

/**
 * Recovers per-team attack and defense values from a set of historical match
 * results using the Maher (1982) iterative fit, with Dixon-Coles (1997) style
 * exponential time decay, and shrinkage toward a seed prior.
 *
 * Output is a per-team-id array of [attack, defense] floats. With no historical
 * data the result equals the seed prior exactly.
 */
final class HistoricalStrengthFitter
{
    public const AVG_ATTACK = 80.0;
    public const AVG_DEFENSE = 80.0;

    public function __construct(
        private readonly float $shrinkageKappa = 12.0,
        private readonly float $timeDecayXi = 0.0019,
        private readonly int $convergenceIterations = 8,
    ) {
    }

    /**
     * @param  array<int, TeamStrength>  $seedStrengths  Keyed by team_id.
     * @param  list<array{home_team_id:int, away_team_id:int, home_goals:int, away_goals:int, played_at:string|null, home_advantage:float}>  $matches
     * @param  string  $referenceDate  ISO date used as "today" for decay weighting; defaults to the most recent match date.
     * @return array<int, array{attack: float, defense: float}> Keyed by team_id.
     */
    public function fit(array $seedStrengths, array $matches, ?string $referenceDate = null): array
    {
        $teamIds = array_keys($seedStrengths);

        $attack = [];
        $defense = [];
        foreach ($teamIds as $id) {
            $attack[$id] = self::AVG_ATTACK;
            $defense[$id] = self::AVG_DEFENSE;
        }

        if ($matches === []) {
            return $this->shrinkToPrior($attack, $defense, [], $seedStrengths);
        }

        $reference = $referenceDate
            ? strtotime($referenceDate)
            : max(array_map(static fn ($m) => strtotime($m['played_at'] ?? 'now'), $matches));

        $weights = [];
        foreach ($matches as $idx => $m) {
            $when = strtotime($m['played_at'] ?? date('Y-m-d', $reference));
            $days = max(0, ($reference - $when) / 86400);
            $weights[$idx] = exp(-$this->timeDecayXi * $days);
        }

        // n_eff per team: sum of weights of matches that involved them
        $nEff = array_fill_keys($teamIds, 0.0);
        foreach ($matches as $idx => $m) {
            $w = $weights[$idx];
            $nEff[$m['home_team_id']] += $w;
            $nEff[$m['away_team_id']] += $w;
        }

        for ($iter = 0; $iter < $this->convergenceIterations; $iter++) {
            $attackNum = array_fill_keys($teamIds, 0.0);
            $attackDen = array_fill_keys($teamIds, 0.0);
            $defenseNum = array_fill_keys($teamIds, 0.0);
            $defenseDen = array_fill_keys($teamIds, 0.0);

            foreach ($matches as $idx => $m) {
                $w = $weights[$idx];
                $hId = $m['home_team_id'];
                $aId = $m['away_team_id'];
                $hf = max(0.5, (float) $m['home_advantage']);
                $defAwayNorm = max(0.1, $defense[$aId] / self::AVG_DEFENSE);
                $defHomeNorm = max(0.1, $defense[$hId] / self::AVG_DEFENSE);
                $attAwayNorm = max(0.1, $attack[$aId] / self::AVG_ATTACK);
                $attHomeNorm = max(0.1, $attack[$hId] / self::AVG_ATTACK);

                // Home team scored against away defense, with home boost.
                $attackNum[$hId] += $w * ($m['home_goals'] / ($defAwayNorm * $hf));
                $attackDen[$hId] += $w;
                // Away team scored against home defense, no boost.
                $attackNum[$aId] += $w * ($m['away_goals'] / $defHomeNorm);
                $attackDen[$aId] += $w;

                // Home team conceded from away attack.
                $defenseNum[$hId] += $w * ($m['away_goals'] / $attAwayNorm);
                $defenseDen[$hId] += $w;
                // Away team conceded from home attack, with home boost.
                $defenseNum[$aId] += $w * ($m['home_goals'] / ($attHomeNorm * $hf));
                $defenseDen[$aId] += $w;
            }

            foreach ($teamIds as $id) {
                if ($attackDen[$id] > 0) {
                    // Scale back to the seed-strength units (attack is roughly goals/game * AVG_ATTACK).
                    $attack[$id] = ($attackNum[$id] / $attackDen[$id]) * self::AVG_ATTACK / $this->baselineLambda();
                }
                if ($defenseDen[$id] > 0) {
                    // Inverse relationship: better defense -> fewer goals conceded -> higher value.
                    $rawGoalsAgainst = $defenseNum[$id] / $defenseDen[$id];
                    $rawGoalsAgainst = max(0.05, $rawGoalsAgainst);
                    $defense[$id] = self::AVG_DEFENSE * ($this->baselineLambda() / $rawGoalsAgainst);
                }
            }
        }

        return $this->shrinkToPrior($attack, $defense, $nEff, $seedStrengths);
    }

    /**
     * The baseline goals-per-game used by the simulator. Mirrors
     * {@see \App\Services\Simulation\StatisticalMatchSimulator::baseLambda}.
     */
    private function baselineLambda(): float
    {
        return 1.35;
    }

    /**
     * @param  array<int, float>  $attack
     * @param  array<int, float>  $defense
     * @param  array<int, float>  $nEff
     * @param  array<int, TeamStrength>  $seedStrengths
     * @return array<int, array{attack: float, defense: float}>
     */
    private function shrinkToPrior(array $attack, array $defense, array $nEff, array $seedStrengths): array
    {
        $out = [];
        foreach ($seedStrengths as $id => $seed) {
            $n = $nEff[$id] ?? 0.0;
            $weight = $n + $this->shrinkageKappa;

            $out[$id] = [
                'attack' => ($n * $attack[$id] + $this->shrinkageKappa * $seed->attack) / $weight,
                'defense' => ($n * $defense[$id] + $this->shrinkageKappa * $seed->defense) / $weight,
            ];
        }

        return $out;
    }
}
