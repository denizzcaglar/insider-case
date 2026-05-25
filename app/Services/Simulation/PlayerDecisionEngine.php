<?php

declare(strict_types=1);

namespace App\Services\Simulation;

use App\Domain\Contracts\Rng;

/**
 * @phpstan-type PlayerArr array{id:int,name:string,position:string,pace:int,shooting:int,passing:int,dribbling:int,defending:int,physical:int,overall:int}
 */
final class PlayerDecisionEngine
{
    public const ACTION_PASS = 1;
    public const ACTION_DRIBBLE = 2;
    public const ACTION_SHOT = 3;

    public function __construct(private readonly Rng $rng) {}

    /**
     * @param  PlayerArr  $onBall
     */
    public function pickAction(array $onBall, int $zone): int
    {
        $weights = match ($zone) {
            TickMatchSimulator::ZONE_OWN_THIRD => [
                self::ACTION_PASS => 92.0,
                self::ACTION_DRIBBLE => 8.0,
                self::ACTION_SHOT => 0.0,
            ],
            TickMatchSimulator::ZONE_MIDFIELD => [
                self::ACTION_PASS => 80.0,
                self::ACTION_DRIBBLE => 18.0,
                self::ACTION_SHOT => 2.0,
            ],
            TickMatchSimulator::ZONE_ATTACKING_THIRD => [
                self::ACTION_PASS => 64.0,
                self::ACTION_DRIBBLE => 25.0,
                self::ACTION_SHOT => 11.0,
            ],
            default => [self::ACTION_PASS => 1.0, self::ACTION_DRIBBLE => 0.0, self::ACTION_SHOT => 0.0],
        };

        $weights[self::ACTION_SHOT] *= ($onBall['shooting'] / 75.0);
        $weights[self::ACTION_DRIBBLE] *= ($onBall['dribbling'] / 75.0);

        if ($onBall['position'] === 'GK') {
            $weights[self::ACTION_SHOT] = 0.0;
            $weights[self::ACTION_DRIBBLE] = 1.0;
            $weights[self::ACTION_PASS] = 99.0;
        }

        return (int) $this->weightedPick($weights);
    }

    /**
     * @param  list<PlayerArr>  $roster
     * @return PlayerArr
     */
    public function pickReceiver(array $roster, int $targetZone, ?int $excludeId = null): array
    {
        $positionWeights = match ($targetZone) {
            TickMatchSimulator::ZONE_OWN_THIRD => ['GK' => 5, 'DEF' => 100, 'MID' => 30, 'FWD' => 5],
            TickMatchSimulator::ZONE_MIDFIELD => ['GK' => 0, 'DEF' => 25, 'MID' => 100, 'FWD' => 25],
            TickMatchSimulator::ZONE_ATTACKING_THIRD => ['GK' => 0, 'DEF' => 5, 'MID' => 35, 'FWD' => 100],
            default => ['GK' => 1, 'DEF' => 1, 'MID' => 1, 'FWD' => 1],
        };

        $weights = [];
        foreach ($roster as $i => $p) {
            if ($excludeId !== null && $p['id'] === $excludeId) {
                continue;
            }
            $weights[$i] = (float) ($positionWeights[$p['position']] ?? 1);
        }

        $idx = (int) $this->weightedPick($weights);

        return $roster[$idx];
    }

    /**
     * @param  list<PlayerArr>  $opposingRoster
     * @return PlayerArr
     */
    public function pickDefender(array $opposingRoster, int $zone): array
    {
        $defenderZone = match ($zone) {
            TickMatchSimulator::ZONE_OWN_THIRD => TickMatchSimulator::ZONE_ATTACKING_THIRD,
            TickMatchSimulator::ZONE_ATTACKING_THIRD => TickMatchSimulator::ZONE_OWN_THIRD,
            default => TickMatchSimulator::ZONE_MIDFIELD,
        };

        $positionWeights = match ($defenderZone) {
            TickMatchSimulator::ZONE_OWN_THIRD => ['GK' => 5, 'DEF' => 100, 'MID' => 25, 'FWD' => 0],
            TickMatchSimulator::ZONE_MIDFIELD => ['GK' => 0, 'DEF' => 30, 'MID' => 100, 'FWD' => 20],
            TickMatchSimulator::ZONE_ATTACKING_THIRD => ['GK' => 0, 'DEF' => 5, 'MID' => 30, 'FWD' => 60],
            default => ['GK' => 1, 'DEF' => 1, 'MID' => 1, 'FWD' => 1],
        };

        $weights = [];
        foreach ($opposingRoster as $i => $p) {
            $base = (float) ($positionWeights[$p['position']] ?? 1);
            $weights[$i] = $base * ($p['defending'] / 80.0);
        }

        $idx = (int) $this->weightedPick($weights);

        return $opposingRoster[$idx];
    }

    /**
     * @param  list<PlayerArr>  $roster
     * @return PlayerArr
     */
    public function pickKeeper(array $roster): array
    {
        foreach ($roster as $p) {
            if ($p['position'] === 'GK') {
                return $p;
            }
        }

        $best = $roster[0];
        foreach ($roster as $p) {
            if ($p['overall'] > $best['overall']) {
                $best = $p;
            }
        }

        return $best;
    }

    /**
     * @param  list<PlayerArr>  $roster
     * @return PlayerArr
     */
    public function pickKickoffPlayer(array $roster): array
    {
        $mids = array_values(array_filter($roster, fn ($p) => $p['position'] === 'MID'));
        if ($mids !== []) {
            return $mids[$this->rng->nextInt(0, count($mids) - 1)];
        }

        $outfield = array_values(array_filter($roster, fn ($p) => $p['position'] !== 'GK'));

        return $outfield[$this->rng->nextInt(0, max(0, count($outfield) - 1))];
    }

    /**
     * @param  array<int|string, float>  $weights
     * @return int|string
     */
    private function weightedPick(array $weights): int|string
    {
        $total = array_sum($weights);
        if ($total <= 0.0) {
            return array_key_first($weights);
        }

        $r = $this->rng->nextFloat() * $total;
        $acc = 0.0;
        foreach ($weights as $key => $w) {
            $acc += $w;
            if ($r < $acc) {
                return $key;
            }
        }

        return array_key_last($weights);
    }
}
