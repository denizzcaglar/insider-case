<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Simulation;

use App\Domain\ValueObjects\MatchEvent as EventVO;
use App\Domain\ValueObjects\TeamStrength;
use App\Models\Team;
use App\Services\Simulation\PlayerDecisionEngine;
use App\Services\Simulation\TickMatchSimulator;
use App\Support\SeededRng;
use Database\Seeders\PlayerSeeder;
use Database\Seeders\TeamSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the tick engine's contract: same seed in, same output out; the clock
 * runs cleanly from 00:00 to 90:00; goal events match the final score; the
 * standard MatchSimulator interface still works.
 *
 * Realism (PL averages) is covered by {@see TickEngineRealismTest}.
 */
final class TickMatchSimulatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TeamSeeder::class, PlayerSeeder::class]);
    }

    private function pairing(): array
    {
        $home = Team::where('short_name', 'MCI')->firstOrFail();
        $away = Team::where('short_name', 'ARS')->firstOrFail();

        return [TeamStrength::fromTeam($home), TeamStrength::fromTeam($away)];
    }

    private function freshSimulator(string $seed): TickMatchSimulator
    {
        $rng = new SeededRng($seed);

        return new TickMatchSimulator($rng, new PlayerDecisionEngine($rng));
    }

    public function test_same_seed_produces_identical_score_and_event_list(): void
    {
        [$home, $away] = $this->pairing();

        $a = $this->freshSimulator('det-1')->simulateWithEvents($home, $away);
        $b = $this->freshSimulator('det-1')->simulateWithEvents($home, $away);

        self::assertSame($a->result->score->home, $b->result->score->home);
        self::assertSame($a->result->score->away, $b->result->score->away);
        self::assertSame(count($a->events), count($b->events));

        foreach ($a->events as $i => $eventA) {
            $eventB = $b->events[$i];
            self::assertSame($eventA->second, $eventB->second, "event $i second");
            self::assertSame($eventA->type, $eventB->type, "event $i type");
            self::assertSame($eventA->teamId, $eventB->teamId, "event $i team");
            self::assertSame($eventA->playerId, $eventB->playerId, "event $i player");
        }
    }

    public function test_kickoff_at_zero_fulltime_at_5400_clock_is_monotonic(): void
    {
        [$home, $away] = $this->pairing();
        $out = $this->freshSimulator('clock')->simulateWithEvents($home, $away);

        self::assertNotEmpty($out->events);
        self::assertSame(EventVO::TYPE_KICKOFF, $out->events[0]->type);
        self::assertSame(0, $out->events[0]->second);

        $last = $out->events[array_key_last($out->events)];
        self::assertSame(EventVO::TYPE_FULLTIME, $last->type);
        self::assertSame(TickMatchSimulator::FULL_TIME, $last->second);

        $previous = -1;
        foreach ($out->events as $i => $event) {
            self::assertGreaterThanOrEqual($previous, $event->second, "event $i breaks monotonic clock");
            self::assertGreaterThanOrEqual(0, $event->second);
            self::assertLessThanOrEqual(TickMatchSimulator::FULL_TIME, $event->second);
            $previous = $event->second;
        }
    }

    public function test_halftime_event_appears_exactly_once_at_2700(): void
    {
        [$home, $away] = $this->pairing();
        $out = $this->freshSimulator('halftime')->simulateWithEvents($home, $away);

        $halftimes = array_filter($out->events, fn (EventVO $e) => $e->type === EventVO::TYPE_HALFTIME);

        self::assertCount(1, $halftimes);
        self::assertSame(TickMatchSimulator::HALF_TIME, array_values($halftimes)[0]->second);
    }

    public function test_goal_events_sum_to_final_score(): void
    {
        [$home, $away] = $this->pairing();
        $out = $this->freshSimulator('goals-sum')->simulateWithEvents($home, $away);

        $goalsByTeam = [$home->id => 0, $away->id => 0];
        foreach ($out->events as $event) {
            if ($event->type === EventVO::TYPE_GOAL) {
                $goalsByTeam[$event->teamId]++;
            }
        }

        self::assertSame($goalsByTeam[$home->id], $out->result->score->home);
        self::assertSame($goalsByTeam[$away->id], $out->result->score->away);
    }

    public function test_simulate_returns_score_only_via_match_simulator_interface(): void
    {
        [$home, $away] = $this->pairing();
        $result = $this->freshSimulator('score-only')->simulate($home, $away);

        self::assertGreaterThanOrEqual(0, $result->score->home);
        self::assertGreaterThanOrEqual(0, $result->score->away);
    }

    public function test_all_emitted_event_types_are_in_the_allowed_set(): void
    {
        [$home, $away] = $this->pairing();
        $out = $this->freshSimulator('types')->simulateWithEvents($home, $away);

        $allowed = [
            EventVO::TYPE_KICKOFF,
            EventVO::TYPE_HALFTIME,
            EventVO::TYPE_FULLTIME,
            EventVO::TYPE_SHOT,
            EventVO::TYPE_SAVE,
            EventVO::TYPE_GOAL,
            EventVO::TYPE_PASS,
            EventVO::TYPE_DRIBBLE,
            EventVO::TYPE_TURNOVER,
        ];

        foreach ($out->events as $event) {
            self::assertContains($event->type, $allowed, "unexpected event type {$event->type}");
        }
    }

    public function test_goal_and_save_events_carry_team_and_player_attribution(): void
    {
        [$home, $away] = $this->pairing();
        $out = $this->freshSimulator('attribution')->simulateWithEvents($home, $away);

        $teamIds = [$home->id, $away->id];

        foreach ($out->events as $event) {
            if (in_array($event->type, [EventVO::TYPE_GOAL, EventVO::TYPE_SAVE, EventVO::TYPE_SHOT], true)) {
                self::assertContains($event->teamId, $teamIds, "shot/save/goal needs a valid team");
                self::assertNotNull($event->playerId, "shot/save/goal needs a player");
                self::assertArrayHasKey('xg', $event->detail);
            }
        }
    }
}
