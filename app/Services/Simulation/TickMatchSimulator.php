<?php

declare(strict_types=1);

namespace App\Services\Simulation;

use App\Domain\Contracts\Rng;
use App\Domain\Contracts\WatchableMatchSimulator;
use App\Domain\ValueObjects\MatchEvent as EventVO;
use App\Domain\ValueObjects\MatchResult;
use App\Domain\ValueObjects\MatchResultWithEvents;
use App\Domain\ValueObjects\Score;
use App\Domain\ValueObjects\TeamStrength;
use App\Models\Player;

/**
 * @phpstan-type PlayerArr array{id:int,name:string,position:string,pace:int,shooting:int,passing:int,dribbling:int,defending:int,physical:int,overall:int}
 */
final class TickMatchSimulator implements WatchableMatchSimulator
{
    public const FULL_TIME = 5400;
    public const HALF_TIME = 2700;

    public const ZONE_OWN_THIRD = 0;
    public const ZONE_MIDFIELD = 1;
    public const ZONE_ATTACKING_THIRD = 2;

    private const RATING_MAX = 99.0;
    private const PASS_PRESSURE = 0.25;
    private const PASS_ADVANCE_PROB = 0.55;

    private const XG_ATTACKING_THIRD = 0.13;
    private const XG_MIDFIELD = 0.03;
    private const MIN_GOAL_PROB = 0.01;
    private const MAX_GOAL_PROB = 0.55;

    private const ON_TARGET_BASE = 0.30;
    private const ON_TARGET_SLOPE = 0.20;

    private const PASS_SECONDS_MIN = 6;
    private const PASS_SECONDS_MAX = 14;
    private const DRIBBLE_SECONDS_MIN = 3;
    private const DRIBBLE_SECONDS_MAX = 7;
    private const SHOT_SECONDS = 2;
    private const GOAL_RESTART_MIN = 50;
    private const GOAL_RESTART_MAX = 90;
    private const SAVE_RESTART_MIN = 15;
    private const SAVE_RESTART_MAX = 30;
    private const SHOT_RESTART_MIN = 10;
    private const SHOT_RESTART_MAX = 22;
    private const TURNOVER_EXTRA_MIN = 0;
    private const TURNOVER_EXTRA_MAX = 3;

    private const EMIT_PASS_OWN = 0.05;
    private const EMIT_PASS_MID = 0.15;
    private const EMIT_PASS_ATT = 0.25;
    private const EMIT_DRIB_MID = 0.30;
    private const EMIT_DRIB_ATT = 0.60;
    private const EMIT_TURNOVER_OWN = 0.20;
    private const EMIT_TURNOVER_MID = 0.20;
    private const EMIT_TURNOVER_ATT = 0.50;

    public function __construct(
        private readonly Rng $rng,
        private readonly PlayerDecisionEngine $decisions,
    ) {}

    public function simulate(TeamStrength $home, TeamStrength $away): MatchResult
    {
        return $this->simulateWithEvents($home, $away)->result;
    }

    public function simulateWithEvents(TeamStrength $home, TeamStrength $away): MatchResultWithEvents
    {
        $rosters = [
            $home->id => $this->loadRoster($home->id),
            $away->id => $this->loadRoster($away->id),
        ];
        $strengths = [$home->id => $home, $away->id => $away];

        $events = [new EventVO(second: 0, type: EventVO::TYPE_KICKOFF)];
        $score = ['home' => 0, 'away' => 0];

        $possession = $home->id;
        $onBall = $this->decisions->pickKickoffPlayer($rosters[$home->id]);
        $zone = self::ZONE_MIDFIELD;
        $second = 0;
        $halftimeEmitted = false;

        while ($second < self::FULL_TIME) {
            if (! $halftimeEmitted && $second >= self::HALF_TIME) {
                $events[] = new EventVO(
                    second: self::HALF_TIME,
                    type: EventVO::TYPE_HALFTIME,
                    detail: ['score_home' => $score['home'], 'score_away' => $score['away']],
                );
                $halftimeEmitted = true;
                $possession = $away->id;
                $onBall = $this->decisions->pickKickoffPlayer($rosters[$away->id]);
                $zone = self::ZONE_MIDFIELD;
                $second = self::HALF_TIME;
                continue;
            }

            $defenderTeamId = $possession === $home->id ? $away->id : $home->id;
            $isHomeAttacking = $possession === $home->id;
            $homeAdvantage = $isHomeAttacking ? $strengths[$home->id]->homeAdvantage : 1.0;

            $action = $this->decisions->pickAction($onBall, $zone);

            $step = $this->resolveAction(
                action: $action,
                onBall: $onBall,
                attackerRoster: $rosters[$possession],
                defenderRoster: $rosters[$defenderTeamId],
                attackerStrength: $strengths[$possession],
                defenderStrength: $strengths[$defenderTeamId],
                zone: $zone,
                homeAdvantage: $homeAdvantage,
                attackingTeamId: $possession,
                defenderTeamId: $defenderTeamId,
                currentSecond: $second,
            );

            $cap = $halftimeEmitted ? self::FULL_TIME : self::HALF_TIME;
            $duration = min($step['secondsElapsed'], $cap - $second);
            $second += $duration;

            if ($step['event'] !== null) {
                $events[] = $step['event'];
                if ($step['event']->type === EventVO::TYPE_GOAL) {
                    if ($isHomeAttacking) {
                        $score['home']++;
                    } else {
                        $score['away']++;
                    }
                }
            }

            $possession = $step['newPossession'];
            $onBall = $step['newOnBall'];
            $zone = $step['newZone'];
        }

        $events[] = new EventVO(
            second: self::FULL_TIME,
            type: EventVO::TYPE_FULLTIME,
            detail: ['score_home' => $score['home'], 'score_away' => $score['away']],
        );

        return new MatchResultWithEvents(
            new MatchResult(new Score($score['home'], $score['away'])),
            $events,
        );
    }

    /**
     * @param  PlayerArr  $onBall
     * @param  list<PlayerArr>  $attackerRoster
     * @param  list<PlayerArr>  $defenderRoster
     * @return array{secondsElapsed:int, event:?EventVO, newPossession:int, newOnBall:PlayerArr, newZone:int}
     */
    private function resolveAction(
        int $action,
        array $onBall,
        array $attackerRoster,
        array $defenderRoster,
        TeamStrength $attackerStrength,
        TeamStrength $defenderStrength,
        int $zone,
        float $homeAdvantage,
        int $attackingTeamId,
        int $defenderTeamId,
        int $currentSecond,
    ): array {
        return match ($action) {
            PlayerDecisionEngine::ACTION_DRIBBLE => $this->resolveDribble(
                $onBall, $defenderRoster, $zone, $attackingTeamId, $defenderTeamId, $currentSecond,
            ),
            PlayerDecisionEngine::ACTION_SHOT => $this->resolveShot(
                $onBall, $defenderRoster, $attackerStrength, $defenderStrength,
                $zone, $homeAdvantage, $attackingTeamId, $defenderTeamId, $currentSecond,
            ),
            default => $this->resolvePass(
                $onBall, $attackerRoster, $defenderRoster, $zone,
                $attackingTeamId, $defenderTeamId, $currentSecond,
            ),
        };
    }

    /**
     * @param  PlayerArr  $onBall
     * @param  list<PlayerArr>  $attackerRoster
     * @param  list<PlayerArr>  $defenderRoster
     * @return array{secondsElapsed:int, event:?EventVO, newPossession:int, newOnBall:PlayerArr, newZone:int}
     */
    private function resolvePass(
        array $onBall,
        array $attackerRoster,
        array $defenderRoster,
        int $zone,
        int $attackingTeamId,
        int $defenderTeamId,
        int $currentSecond,
    ): array {
        $defender = $this->decisions->pickDefender($defenderRoster, $zone);

        $passSkill = (float) $onBall['passing'];
        $pressure = (float) $defender['defending'] * self::PASS_PRESSURE;
        $successProb = $passSkill / max(1.0, $passSkill + $pressure);

        $duration = $this->rng->nextInt(self::PASS_SECONDS_MIN, self::PASS_SECONDS_MAX);

        if ($this->rng->nextFloat() < $successProb) {
            $advance = $this->rng->nextFloat() < self::PASS_ADVANCE_PROB;
            $newZone = $advance ? min(self::ZONE_ATTACKING_THIRD, $zone + 1) : $zone;
            $receiver = $this->decisions->pickReceiver($attackerRoster, $newZone, $onBall['id']);

            $emitRate = $this->emitPassRate($newZone);
            $event = $this->rng->nextFloat() < $emitRate
                ? new EventVO(
                    second: $currentSecond,
                    type: EventVO::TYPE_PASS,
                    teamId: $attackingTeamId,
                    playerId: $receiver['id'],
                    detail: ['zone' => self::zoneLabel($newZone)],
                )
                : null;

            return [
                'secondsElapsed' => $duration,
                'event' => $event,
                'newPossession' => $attackingTeamId,
                'newOnBall' => $receiver,
                'newZone' => $newZone,
            ];
        }

        $duration += $this->rng->nextInt(self::TURNOVER_EXTRA_MIN, self::TURNOVER_EXTRA_MAX);
        $defenderZone = $this->mirrorZone($zone);

        $event = $this->rng->nextFloat() < $this->emitTurnoverRate($zone)
            ? new EventVO(
                second: $currentSecond,
                type: EventVO::TYPE_TURNOVER,
                teamId: $defenderTeamId,
                playerId: $defender['id'],
                detail: ['zone' => self::zoneLabel($defenderZone)],
            )
            : null;

        return [
            'secondsElapsed' => $duration,
            'event' => $event,
            'newPossession' => $defenderTeamId,
            'newOnBall' => $defender,
            'newZone' => $defenderZone,
        ];
    }

    /**
     * @param  PlayerArr  $onBall
     * @param  list<PlayerArr>  $defenderRoster
     * @return array{secondsElapsed:int, event:?EventVO, newPossession:int, newOnBall:PlayerArr, newZone:int}
     */
    private function resolveDribble(
        array $onBall,
        array $defenderRoster,
        int $zone,
        int $attackingTeamId,
        int $defenderTeamId,
        int $currentSecond,
    ): array {
        $defender = $this->decisions->pickDefender($defenderRoster, $zone);

        $attackerScore = ($onBall['dribbling'] + $onBall['pace']) / 2.0;
        $defenderScore = ($defender['defending'] + $defender['pace']) / 2.0;
        $successProb = $attackerScore / max(1.0, $attackerScore + $defenderScore);

        $duration = $this->rng->nextInt(self::DRIBBLE_SECONDS_MIN, self::DRIBBLE_SECONDS_MAX);

        if ($this->rng->nextFloat() < $successProb) {
            $newZone = min(self::ZONE_ATTACKING_THIRD, $zone + 1);

            $event = $this->rng->nextFloat() < $this->emitDribbleRate($newZone)
                ? new EventVO(
                    second: $currentSecond,
                    type: EventVO::TYPE_DRIBBLE,
                    teamId: $attackingTeamId,
                    playerId: $onBall['id'],
                    detail: ['zone' => self::zoneLabel($newZone)],
                )
                : null;

            return [
                'secondsElapsed' => $duration,
                'event' => $event,
                'newPossession' => $attackingTeamId,
                'newOnBall' => $onBall,
                'newZone' => $newZone,
            ];
        }

        $duration += $this->rng->nextInt(self::TURNOVER_EXTRA_MIN, self::TURNOVER_EXTRA_MAX);
        $defenderZone = $this->mirrorZone($zone);

        $event = $this->rng->nextFloat() < $this->emitTurnoverRate($zone)
            ? new EventVO(
                second: $currentSecond,
                type: EventVO::TYPE_TURNOVER,
                teamId: $defenderTeamId,
                playerId: $defender['id'],
                detail: ['zone' => self::zoneLabel($defenderZone)],
            )
            : null;

        return [
            'secondsElapsed' => $duration,
            'event' => $event,
            'newPossession' => $defenderTeamId,
            'newOnBall' => $defender,
            'newZone' => $defenderZone,
        ];
    }

    private function emitPassRate(int $zone): float
    {
        return match ($zone) {
            self::ZONE_OWN_THIRD => self::EMIT_PASS_OWN,
            self::ZONE_ATTACKING_THIRD => self::EMIT_PASS_ATT,
            default => self::EMIT_PASS_MID,
        };
    }

    private function emitDribbleRate(int $zone): float
    {
        return match ($zone) {
            self::ZONE_ATTACKING_THIRD => self::EMIT_DRIB_ATT,
            default => self::EMIT_DRIB_MID,
        };
    }

    private function emitTurnoverRate(int $zone): float
    {
        return match ($zone) {
            self::ZONE_OWN_THIRD => self::EMIT_TURNOVER_OWN,
            self::ZONE_ATTACKING_THIRD => self::EMIT_TURNOVER_ATT,
            default => self::EMIT_TURNOVER_MID,
        };
    }

    /**
     * @param  PlayerArr  $onBall
     * @param  list<PlayerArr>  $defenderRoster
     * @return array{secondsElapsed:int, event:?EventVO, newPossession:int, newOnBall:PlayerArr, newZone:int}
     */
    private function resolveShot(
        array $onBall,
        array $defenderRoster,
        TeamStrength $attackerStrength,
        TeamStrength $defenderStrength,
        int $zone,
        float $homeAdvantage,
        int $attackingTeamId,
        int $defenderTeamId,
        int $currentSecond,
    ): array {
        $keeper = $this->decisions->pickKeeper($defenderRoster);

        $baseXg = $zone === self::ZONE_ATTACKING_THIRD ? self::XG_ATTACKING_THIRD : self::XG_MIDFIELD;
        $finishingFactor = $onBall['shooting'] / self::RATING_MAX;
        $strengthFactor = $attackerStrength->attack / max(1.0, (float) $defenderStrength->defense);
        $goalProb = max(
            self::MIN_GOAL_PROB,
            min(self::MAX_GOAL_PROB, $baseXg * $finishingFactor * $strengthFactor * $homeAdvantage),
        );

        $onTargetProb = self::ON_TARGET_BASE + self::ON_TARGET_SLOPE * ($onBall['shooting'] / self::RATING_MAX);
        $onTargetProb = max(0.05, min(0.95, $onTargetProb));

        $r = $this->rng->nextFloat();

        $zoneLabel = self::zoneLabel($zone);

        if ($r < $goalProb) {
            $duration = self::SHOT_SECONDS + $this->rng->nextInt(self::GOAL_RESTART_MIN, self::GOAL_RESTART_MAX);
            $event = new EventVO(
                second: $currentSecond,
                type: EventVO::TYPE_GOAL,
                teamId: $attackingTeamId,
                playerId: $onBall['id'],
                detail: ['xg' => round($goalProb, 3), 'zone' => $zoneLabel],
            );

            return [
                'secondsElapsed' => $duration,
                'event' => $event,
                'newPossession' => $defenderTeamId,
                'newOnBall' => $this->decisions->pickKickoffPlayer($defenderRoster),
                'newZone' => self::ZONE_MIDFIELD,
            ];
        }

        if ($r < $onTargetProb) {
            $duration = self::SHOT_SECONDS + $this->rng->nextInt(self::SAVE_RESTART_MIN, self::SAVE_RESTART_MAX);
            $event = new EventVO(
                second: $currentSecond,
                type: EventVO::TYPE_SAVE,
                teamId: $attackingTeamId,
                playerId: $onBall['id'],
                detail: ['xg' => round($goalProb, 3), 'zone' => $zoneLabel, 'keeper_id' => $keeper['id']],
            );

            return [
                'secondsElapsed' => $duration,
                'event' => $event,
                'newPossession' => $defenderTeamId,
                'newOnBall' => $keeper,
                'newZone' => self::ZONE_OWN_THIRD,
            ];
        }

        $duration = self::SHOT_SECONDS + $this->rng->nextInt(self::SHOT_RESTART_MIN, self::SHOT_RESTART_MAX);
        $event = new EventVO(
            second: $currentSecond,
            type: EventVO::TYPE_SHOT,
            teamId: $attackingTeamId,
            playerId: $onBall['id'],
            detail: ['xg' => round($goalProb, 3), 'zone' => $zoneLabel],
        );

        return [
            'secondsElapsed' => $duration,
            'event' => $event,
            'newPossession' => $defenderTeamId,
            'newOnBall' => $keeper,
            'newZone' => self::ZONE_OWN_THIRD,
        ];
    }

    private function mirrorZone(int $zone): int
    {
        return match ($zone) {
            self::ZONE_OWN_THIRD => self::ZONE_ATTACKING_THIRD,
            self::ZONE_ATTACKING_THIRD => self::ZONE_OWN_THIRD,
            default => self::ZONE_MIDFIELD,
        };
    }

    private static function zoneLabel(int $zone): string
    {
        return match ($zone) {
            self::ZONE_OWN_THIRD => 'OWN_THIRD',
            self::ZONE_MIDFIELD => 'MIDFIELD',
            self::ZONE_ATTACKING_THIRD => 'ATTACKING_THIRD',
            default => 'UNKNOWN',
        };
    }

    /**
     * @return list<array{id:int,name:string,position:string,pace:int,shooting:int,passing:int,dribbling:int,defending:int,physical:int,overall:int}>
     */
    private function loadRoster(int $teamId): array
    {
        $rank = ['GK' => 0, 'DEF' => 1, 'MID' => 2, 'FWD' => 3];

        return Player::query()
            ->where('team_id', $teamId)
            ->orderBy('id')
            ->get([
                'id', 'name', 'position', 'pace', 'shooting',
                'passing', 'dribbling', 'defending', 'physical', 'overall',
            ])
            ->sortBy(
                fn (Player $p) => sprintf('%d-%010d', $rank[$p->position] ?? 9, $p->id),
            )
            ->values()
            ->map(fn (Player $p) => [
                'id' => (int) $p->id,
                'name' => $p->name,
                'position' => $p->position,
                'pace' => (int) $p->pace,
                'shooting' => (int) $p->shooting,
                'passing' => (int) $p->passing,
                'dribbling' => (int) $p->dribbling,
                'defending' => (int) $p->defending,
                'physical' => (int) $p->physical,
                'overall' => (int) $p->overall,
            ])
            ->all();
    }
}
