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
 * Calibration guard for the tick engine. The seed teams are four top-tier sides,
 * so their pairings sit slightly above the Premier League averages: ~3 goals,
 * ~28 shots, ~46% on target, ~11% conversion. Bounds are deliberately loose so
 * ordinary variance does not flake the suite; they fail when a constant drift
 * pushes the engine into 0-goal or basketball-score territory.
 *
 * Runs 48 matches (6 pairings * 8 seeds). With a per-match cost of ~5ms this
 * stays well under 1 second.
 */
final class TickEngineRealismTest extends TestCase
{
    use RefreshDatabase;

    private const SEEDS_PER_PAIRING = 8;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([TeamSeeder::class, PlayerSeeder::class]);
    }

    public function test_aggregate_output_lands_in_real_football_ranges(): void
    {
        $teams = Team::orderBy('id')->get();
        $strengths = $teams->mapWithKeys(
            fn (Team $t) => [$t->id => TeamStrength::fromTeam($t)]
        )->all();

        $matches = 0;
        $homeGoals = 0;
        $awayGoals = 0;
        $shots = 0;
        $onTarget = 0;
        $seed = 9000;

        foreach ($teams as $home) {
            foreach ($teams as $away) {
                if ($home->id === $away->id) {
                    continue;
                }
                for ($i = 0; $i < self::SEEDS_PER_PAIRING; $i++) {
                    $rng = new SeededRng('realism-'.$seed++);
                    $sim = new TickMatchSimulator($rng, new PlayerDecisionEngine($rng));
                    $out = $sim->simulateWithEvents($strengths[$home->id], $strengths[$away->id]);

                    $matches++;
                    $homeGoals += $out->result->score->home;
                    $awayGoals += $out->result->score->away;

                    foreach ($out->events as $event) {
                        if (in_array($event->type, [EventVO::TYPE_SHOT, EventVO::TYPE_SAVE, EventVO::TYPE_GOAL], true)) {
                            $shots++;
                        }
                        if (in_array($event->type, [EventVO::TYPE_SAVE, EventVO::TYPE_GOAL], true)) {
                            $onTarget++;
                        }
                    }
                }
            }
        }

        $goals = $homeGoals + $awayGoals;
        $goalsPerMatch = $goals / $matches;
        $shotsPerMatch = $shots / $matches;
        $onTargetPct = 100 * $onTarget / max(1, $shots);
        $conversionPct = 100 * $goals / max(1, $shots);

        self::assertGreaterThan(2.3, $goalsPerMatch, "goals/match too low: {$goalsPerMatch}");
        self::assertLessThan(4.0, $goalsPerMatch, "goals/match too high: {$goalsPerMatch}");
        self::assertGreaterThan(22, $shotsPerMatch, "shots/match too low: {$shotsPerMatch}");
        self::assertLessThan(36, $shotsPerMatch, "shots/match too high: {$shotsPerMatch}");
        self::assertGreaterThan(40, $onTargetPct, "on-target % too low: {$onTargetPct}");
        self::assertLessThan(58, $onTargetPct, "on-target % too high: {$onTargetPct}");
        self::assertGreaterThan(7, $conversionPct, "conversion % too low: {$conversionPct}");
        self::assertLessThan(15, $conversionPct, "conversion % too high: {$conversionPct}");

        // Home advantage should at least not invert in aggregate over 48 matches.
        self::assertGreaterThanOrEqual($awayGoals, $homeGoals, "home should not be outscored in aggregate (home={$homeGoals}, away={$awayGoals})");
    }
}
