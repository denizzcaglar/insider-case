# Tick-based match engine

Date: 2026-05-25
Branch: `feat/tick-engine` (off `main`)
Status: design approved, ready for implementation plan

## Goal

Add a second match engine alongside the existing statistical one. The new engine plays the match minute by minute, produces a sequence of events with timestamps, and powers a "Watch match" experience that streams the action over Server-Sent Events. The fast statistical engine continues to power batch paths (next-week, play-all, the Monte Carlo predictor); the tick engine is opt-in per call site and is only used when a single user watches a single match.

## Non-goals

The following are intentionally out of scope. We will not build them in v1.

- Pause, resume, or scrub controls during a watched match.
- User-selectable replay speed. Speed is fixed at 60x (one match-minute per real-second) in v1.
- Substitutions, fouls, cards, set pieces, injuries.
- Mid-stream speed changes. Once the stream is open it runs at 60x; the only escape hatch is the Skip button.
- Plugging the tick engine into the Monte Carlo predictor. The predictor runs ~120k simulations per request; the tick engine cannot meet that budget and would not improve prediction quality.
- Replacing the existing Gemini commentary system. The two systems are independent.
- Background simulation. A fixture only acquires events when a user clicks Watch.

## What is preserved from `main`

This branch is strictly additive on top of `main`. None of the following is touched:

- `MatchSimulator` interface contract and signature.
- `StatisticalMatchSimulator` and its container binding.
- `MonteCarloChampionshipPredictor` signature, including its `MatchSimulator` dependency.
- `CachedChampionshipPredictor` and `PredictionCacheStore`.
- `HistoricalStrengthFitter`, `CurrentFormTracker`, `EffectiveStrengthBuilder`.
- `HeadToHeadResolver` and the `StandingsCalculator` comparator list.
- `LeagueService` public API (we add one new method, do not change existing ones).
- The `MatchCommentary` system (model, service, generator, controller, config, migration).
- The `PredictionSnapshot` system.
- The `RejectHistoricalSeasonMutation` middleware.
- The `is_historical` flag on seasons and the historical-matches seed pipeline.
- All existing migrations, models, controllers, routes.

## Architecture and seams

### Interfaces

```php
// existing, unchanged
interface MatchSimulator {
    public function simulate(Team $home, Team $away): MatchResult;
}

// new
interface WatchableMatchSimulator extends MatchSimulator {
    public function simulateWithEvents(Team $home, Team $away): MatchResultWithEvents;
}

// new
interface MatchSimulatorFactory {
    public function forWatching(?int $seed = null): WatchableMatchSimulator;
}
```

The factory has exactly one method. The batch and predictor paths do not go through the factory; they keep their existing dependency on the bare `MatchSimulator` interface and pay no method-call overhead on the hot path. The factory exists to wire `TickMatchSimulator` together with `PlayerDecisionEngine` and a freshly seeded `Rng`. The two collaborators must share the same RNG to keep the match deterministic, which container auto-wiring cannot guarantee on its own.

### Default factory

```php
final class DefaultMatchSimulatorFactory implements MatchSimulatorFactory {
    public function forWatching(?int $seed = null): WatchableMatchSimulator {
        $rng = new SeededRng($seed);
        return new TickMatchSimulator(
            rng: $rng,
            decisions: new PlayerDecisionEngine($rng),
        );
    }
}
```

### Container bindings

Delta vs `main` in `DomainServiceProvider`:

- Unchanged: `MatchSimulator::class` -> `StatisticalMatchSimulator::class`.
- New: `MatchSimulatorFactory::class` -> `DefaultMatchSimulatorFactory::class` (singleton).

The only consumer of the new binding is `MatchStreamController`.

### Caller integration

- `MonteCarloChampionshipPredictor`: zero changes.
- `LeagueService::playWeek`, `playAll`, `editResult`: zero changes.
- `MatchStreamController` (new): receives `MatchSimulatorFactory`, calls `forWatching($seed)`, hands the resulting `MatchResultWithEvents` to `LeagueService::recordWatchedFixture` for persistence.

### Invariants preserved

- Standings remain derived from played fixtures. A watched match's score is saved on the fixture row in the same way as a statistical play, and `StandingsCalculator` rebuilds from played fixtures unchanged. There is no special case for tick-engine results.
- The tick engine reads team strength via `EffectiveStrengthBuilder`. It does not read raw `Team.attack`/`Team.defense` directly. This is the single-source-of-truth rule the statistical engine also follows.
- Determinism: same seed in, same scoreline and same event list out. The watch controller derives the seed from `(season.seed, fixture.id)` so a given fixture on a given season always plays the same way the first time it is watched.

## Engine internals

`TickMatchSimulator` runs one match in a single synchronous call. The loop is possession-driven: each iteration is one action by the team in possession, and the clock advances by that action's duration. A match takes roughly 150 to 250 iterations, not 5400.

### Loop sketch

```
simulateWithEvents(home, away):
  # one-time setup, outside the loop
  homeRoster      = preload(home.players)              # plain arrays, not Eloquent
  awayRoster      = preload(away.players)
  homeStrength    = EffectiveStrengthBuilder.for(home, asOfWeek)
  awayStrength    = EffectiveStrengthBuilder.for(away, asOfWeek)
  events          = []                                  # grows to ~30-50
  state = {
    possessionTeam : home,
    onBall         : pickStartingMidfielder(homeRoster),
    zone           : MIDFIELD,
    second         : 0,
    score          : {home: 0, away: 0},
  }
  emit(events, KICKOFF, 0)

  while state.second < FULL_TIME:
    if state.second >= HALF_TIME and not halftimeEmitted:
      emit(events, HALFTIME, HALF_TIME)
      reset possession to away kickoff in MIDFIELD
      halftimeEmitted = true

    action = decideAction(state, attackerStrength, decisions, rng)
    result = resolve(action, state, attackerStrength, defenderStrength, rng)

    state.second        += result.secondsElapsed
    state.zone           = result.newZone
    state.possessionTeam = result.newPossessionTeam
    state.onBall         = result.newOnBall
    if result.event:
      emit(events, result.event)
    if result.event.type == GOAL:
      state.score[scoringTeam] += 1

  emit(events, FULLTIME, FULL_TIME)
  return new MatchResultWithEvents(score, events)
```

### Zones

Three zones, relative to the team in possession: `OWN_THIRD`, `MIDFIELD`, `ATTACKING_THIRD`. Zone changes happen only on a successful pass or dribble. Shots originate from `ATTACKING_THIRD` with high xG, or rarely from `MIDFIELD` as long-range efforts with low xG.

### Internal actions

The loop has three actions. Six event types are emitted; the rest of the loop machinery is internal.

| Action | When chosen | Contest | Outcome |
| --- | --- | --- | --- |
| Pass    | default in any zone, more common in `OWN_THIRD` and `MIDFIELD` | `passer.passing` vs `defender.defending * PASS_PRESSURE` | Success: ball stays or advances one zone (`PASS_ADVANCE_PROB`). Failure: turnover, defender takes possession in that zone. |
| Dribble | weighted by `onBall.dribbling`, more likely in `ATTACKING_THIRD` | `(attacker.dribbling + attacker.pace) / 2` vs `(defender.defending + defender.pace) / 2` | Success: advance one zone, retain possession. Failure: turnover. |
| Shot    | only in `ATTACKING_THIRD` (high xG) or speculative from `MIDFIELD` (low xG) | xG * `(shooter.finishing / RATING_MAX)` vs `keeper.gk_rating / RATING_MAX` | Off-target: emit `shot`. On-target and saved: emit `save`. On-target and scored: emit `goal`. All three reset possession to the opposing keeper in their `OWN_THIRD`. |

### Event types

Exactly six. Reviewer-readable, queryable, complete.

- `kickoff`
- `halftime`
- `fulltime`
- `shot` (off-target attempt)
- `save` (on-target attempt saved by keeper)
- `goal` (on-target attempt scored)

Aggregate stats derive from these without further data: `shots_total = count(shot|save|goal)`, `on_target = count(save|goal)`, `conversion = goals / shots_total`. Routine actions (pass, dribble, tackle, turnover) are computed in the loop but never persisted; that is what keeps `match_events` to roughly 30 to 50 rows per match.

### Action durations

Seconds, drawn uniformly per action. They pace the match to roughly 55 to 60 minutes of ball-in-play.

```
PASS_SECONDS          = [2, 5]
DRIBBLE_SECONDS       = [2, 4]
SHOT_SECONDS          = 2
GOAL_RESTART_SECONDS  = [40, 70]   # celebration plus restart
SAVE_RESTART_SECONDS  = [10, 25]   # corner or goal kick
SHOT_RESTART_SECONDS  = [8, 18]    # off-target: goal kick or throw-in
TURNOVER_EXTRA        = [0, 2]     # brief regroup after losing the ball
```

If an action's duration would push the clock past `HALF_TIME` or `FULL_TIME`, the clock clamps to the boundary, the boundary event is emitted first, and the next iteration starts cleanly at the new period.

### Calibration targets

Premier League averages, both teams aggregated:

- 2.8 to 3.0 goals per match
- 27 shots per match
- 45 to 50 percent shots on target
- 11 percent conversion
- Modest home advantage visible in aggregate

The four seed teams (Manchester City, Liverpool, Arsenal, Chelsea) are all top-tier, so their pairings should sit slightly above these averages. A mid-table side simulated against them would land near the baseline. The realism test asserts loose bounds rather than exact values so that ordinary variance does not flake the suite.

### How this avoids the previous iteration's slowness

The previous iteration on the `tick-engine` branch was slow for three reasons. We address each one explicitly.

1. Eloquent access inside the loop. Fix: all player data is hoisted into plain `(id, name, position, attack, dribbling, pace, defending, gk_rating, finishing, passing)` structs before the loop runs. The loop touches arrays, not models.
2. Value object allocation on every micro-action. Fix: `MatchEvent` instances are constructed only when an event is going to be emitted (roughly 30 to 50 times per match), not on every loop iteration. `PlayerDecisionEngine` returns scalars, not value objects.
3. Slow realism test (144 sequential matches). Fix: the new realism test runs 6 pairings times 8 seeds, totalling 48 matches, with wider tolerance bands on the calibration assertions. It still catches drift, and it runs in 2 to 3 seconds instead of 20.

## Data model

Two new tables. Both additive. No existing migration is touched.

### `players` table

```php
Schema::create('players', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();
    $table->string('name');
    $table->enum('position', ['GK', 'DEF', 'MID', 'FWD']);
    $table->unsignedTinyInteger('shirt_number')->nullable();

    $table->unsignedTinyInteger('pace');
    $table->unsignedTinyInteger('passing');
    $table->unsignedTinyInteger('dribbling');
    $table->unsignedTinyInteger('defending');
    $table->unsignedTinyInteger('finishing');
    $table->unsignedTinyInteger('gk_rating');  # meaningful only for GKs

    $table->timestamps();
    $table->index(['team_id', 'position']);
});
```

Squad shape: 11 players per team, 1 GK plus 4 DEF plus 4 MID plus 2 FWD. No substitutes in v1. 44 rows total, seeded from `database/data/players.json`. The seed file is salvaged from the previous `tick-engine` branch as a starting point; attributes may be re-tuned during calibration to hit the targets above. Player names are taken from the actual current squads of the four clubs.

### `match_events` table

```php
Schema::create('match_events', function (Blueprint $table) {
    $table->id();
    $table->foreignId('fixture_id')->constrained()->cascadeOnDelete();
    $table->unsignedSmallInteger('second');   # 0..5400, master clock
    $table->enum('type', ['kickoff', 'halftime', 'fulltime', 'shot', 'save', 'goal']);
    $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
    $table->foreignId('player_id')->nullable()->constrained('players')->nullOnDelete();
    $table->json('detail')->nullable();
    $table->timestamp('created_at')->nullable();
    $table->index(['fixture_id', 'second']);
});
```

No `updated_at`. Events are immutable. Minute is derived as a Laravel accessor (`$event->minute = intdiv($second, 60)`) and is not stored.

### `detail` JSON shapes

Small, optional, makes the feed renderable without re-deriving anything:

| Type | Shape |
| --- | --- |
| `kickoff`  | `{}` |
| `halftime` | `{score_home: 1, score_away: 0}` |
| `fulltime` | `{score_home: 2, score_away: 1}` |
| `shot`     | `{xg: 0.18, zone: "ATTACKING_THIRD"}` |
| `save`     | `{xg: 0.22, keeper_id: 45, zone: "ATTACKING_THIRD"}` |
| `goal`     | `{xg: 0.31, zone: "ATTACKING_THIRD", assist_player_id: null}` |

### Eloquent models

```php
class Player extends Model {
    protected $fillable = ['team_id', 'name', 'position', 'shirt_number',
                           'pace', 'passing', 'dribbling', 'defending',
                           'finishing', 'gk_rating'];
    public function team() { return $this->belongsTo(Team::class); }
}

class MatchEvent extends Model {
    public const UPDATED_AT = null;
    protected $fillable = ['fixture_id', 'second', 'type', 'team_id', 'player_id', 'detail'];
    protected $casts = ['detail' => 'array'];

    public function fixture() { return $this->belongsTo(Fixture::class); }
    public function team()    { return $this->belongsTo(Team::class); }
    public function player()  { return $this->belongsTo(Player::class); }

    public function getMinuteAttribute(): int { return intdiv($this->second, 60); }
    public function clock(): string {
        return sprintf('%02d:%02d', $this->minute, $this->second % 60);
    }
}
```

Additive relations on existing models:

```php
# Team model
public function players() { return $this->hasMany(Player::class); }

# Fixture model
public function events() { return $this->hasMany(MatchEvent::class)->orderBy('second'); }
```

### Value objects

In `app/Domain/ValueObjects/`:

- `MatchEvent`: immutable, distinct from the Eloquent model of the same name. Fields: `int $second, string $type, ?int $teamId, ?int $playerId, array $detail`. Same name in two namespaces is the standard Laravel convention; use statements disambiguate.
- `MatchResultWithEvents`: wraps `MatchResult $result, array<MatchEvent> $events`. Return type of `WatchableMatchSimulator::simulateWithEvents`.

### Seeders

- New `PlayerSeeder`, invoked from `DatabaseSeeder` after `TeamSeeder`. Reads `database/data/players.json`. Idempotent (truncate-then-insert) so `db:seed` is safe to re-run.
- Existing seeders (`TeamSeeder`, `SeasonSeeder`, `HistoricalSeasonSeeder`) are unchanged.

### Migration ordering

Two new migrations with timestamps after the existing ones, so `migrate:fresh --seed` runs them at the end:

- `2026_05_25_000001_create_players_table.php`
- `2026_05_25_000002_create_match_events_table.php`

## HTTP endpoints

### `GET /api/fixtures/{fixture}/watch?speed=60`

Server-Sent Events stream. Browser `EventSource` only supports GET, so this is GET despite the first call having a side effect (simulation plus persistence). Subsequent calls on the same fixture are pure replay.

Query params:

- `speed`: match-seconds per real-second. Default 60. Range [1, 3600] clamped at the request layer.

Controller flow:

1. Resolve fixture, ensure its season is not historical (403 if so). The existing `RejectHistoricalSeasonMutation` middleware only fires on writes, so the GET endpoint checks explicitly.
2. Decide the path:
   - Fixture has `match_events`: replay-only, no simulation.
   - Fixture is unplayed: simulate-then-replay (requires the lock).
   - Fixture is played but has no events (played via the statistical engine): respond 409 Conflict with a message that re-watching would change the score.
3. Simulate-then-replay path:
   - Acquire `Cache::lock("watch:fixture:{id}", 30)` blocking up to 10s.
   - Re-check after acquisition; another request may have completed the simulation.
   - Derive seed: `crc32($season->seed . ':' . $fixture->id)`.
   - Call `$factory->forWatching($seed)->simulateWithEvents($home, $away)`.
   - Hand the result to `LeagueService::recordWatchedFixture($fixture, $resultWithEvents)`, which writes the score and bulk-inserts the events in one transaction, then busts the prediction cache for the season and (if the watched match completes a week) records a prediction snapshot.
4. Stream events from `$fixture->events` (now populated) with pacing.

Error and edge cases:

- Fixture not found: 404.
- Season is historical: 403.
- Played via stat engine with no events: 409.
- Lock acquisition times out: 503.
- Client disconnect mid-stream: loop exits silently. Events are already persisted, so refreshing re-streams from the database.
- `speed` zero or non-integer: 422 via a `FormRequest`.

SSE wire format:

Headers:

```
Content-Type:      text/event-stream
Cache-Control:     no-cache
X-Accel-Buffering: no
Connection:        keep-alive
```

Per event:

```
event: match-event
data: {"second":347,"clock":"05:47","type":"shot","team_id":1,
       "player_id":12,"player_name":"Saka","detail":{"xg":0.18,"zone":"ATTACKING_THIRD"}}

```

(blank line terminates the event per the SSE spec)

Terminator:

```
event: complete
data: {"score_home":2,"score_away":1,"events_total":34}

```

Then close. The trailing `complete` event lets the client cleanly transition out of live mode without inferring it from `EventSource.onerror`.

Pacing loop:

```php
$prevSecond = 0;
foreach ($events as $event) {
    if (connection_aborted()) return;
    $waitMicros = (int) (($event->second - $prevSecond) / $speed * 1_000_000);
    if ($waitMicros > 0) usleep($waitMicros);
    echo "event: match-event\ndata: " . json_encode($payload) . "\n\n";
    @ob_flush(); flush();
    $prevSecond = $event->second;
}
```

`@ob_end_clean()` runs once at the start of the callable to drop Laravel's middleware buffering. Sail runs nginx in front of php-fpm, so long-lived connections work; `X-Accel-Buffering: no` prevents nginx from holding the stream.

### `GET /api/fixtures/{fixture}/events`

JSON, no streaming. Pairs naturally with the SSE endpoint and powers the Skip button.

Response:

```json
{
  "fixture_id": 17,
  "score": {"home": 2, "away": 1},
  "events": [
    {"second": 0, "clock": "00:00", "type": "kickoff", "team_id": null, "player_id": null, "detail": {}},
    {"second": 347, "clock": "05:47", "type": "shot", "team_id": 1, "player_id": 12, "player_name": "Saka", "detail": {"xg": 0.18, "zone": "ATTACKING_THIRD"}}
  ]
}
```

404 if the fixture has no events. Cheap, side-effect-free, useful beyond Skip (a future match-details page or external integration can read it).

### `LeagueService::recordWatchedFixture`

New method on the existing `LeagueService`. Only the watch controller calls it.

```php
public function recordWatchedFixture(Fixture $fixture, MatchResultWithEvents $r): void {
    DB::transaction(function () use ($fixture, $r) {
        $this->saveFixtureScore($fixture, $r->result);    // existing helper
        MatchEvent::insert($this->toRows($fixture, $r->events));
        $this->predictionCache->bustForSeason($fixture->season_id);
        if ($this->isWeekComplete($fixture)) {
            $this->snapshotPredictionsForWeek($fixture);
        }
    });
}
```

Standings update through the same path as `next-week` and `play-all`: `StandingsCalculator` rebuilds from played fixtures. The prediction cache bust matches the existing per-season busting in `playWeek`, `playAll`, `editResult`, and `reset`.

## UI integration

Single Blade page (`resources/views/league.blade.php`) gets one new modal and per-fixture button wiring. Vanilla JS, no build step, no new dependencies. Browser-native `EventSource` does the streaming.

### Per-fixture button states

In the existing fixtures list:

| Fixture state | Button shown | Action |
| --- | --- | --- |
| Unplayed | Watch | Opens modal; SSE simulates then replays |
| Played with events | Replay | Opens modal; SSE replays from `match_events` |
| Played without events (pre-existing stat-engine fixture) | none | Existing per-fixture controls only |

The 409 path is unreachable from the UI: stat-played fixtures simply do not show a Watch button. The button visibility is computed from `fixture.played_at` plus a new `events_count` field added to `FixtureResource`.

### Modal layout

```
+------------------------------------------------------------------+
| Manchester City   2 - 1   Arsenal                          [X]   |
| ---------------------------------------------------------------- |
| 00:00  KICK  Kickoff.                                            |
| 12:08  SHOT  Haaland off target.                                 |
| 34:12  GOAL  Saka opens the scoring for Arsenal. 0-1.            |
| 45:00  HT    Half time. City 0-1 Arsenal.                        |
| 67:45  GOAL  Foden equalises for City. 1-1.                      |
| 78:01  SAVE  Salah's effort saved by Ederson.                    |
| 87:23  GOAL  Haaland scores for City. 2-1.                       |
| 90:00  FT    Full time. City 2-1 Arsenal.                        |
| ---------------------------------------------------------------- |
| Speed: 60x                              [Skip to end]   [Close]  |
+------------------------------------------------------------------+
```

Three columns per event row: clock (`MM:SS`), 4-char type badge (`KICK`, `SHOT`, `SAVE`, `GOAL`, `HT`, `FT`), sentence. Type badge is plain text. Feed is chronological with auto-scroll to the newest row.

### Behavioural states

| State | Trigger | UI |
| --- | --- | --- |
| Opening | Click Watch or Replay | Modal opens, header shows fixture, score `0 - 0`, feed empty |
| Streaming | `EventSource` open, receiving `match-event` | New row appended, auto-scrolls; goal events also update the big score |
| Complete | `complete` event received | Full-time row shown; Skip button hidden; Close stays |
| Skipping | User clicks Skip | EventSource closed; client fetches `GET /api/fixtures/{id}/events`; remaining rows rendered instantly; transition to Complete |
| Error | `EventSource.onerror` while open | Banner: "Connection lost. [Retry] [Close]". Retry re-opens the stream from second 0; client de-dupes by `(second, type, player_id)` to avoid double-printing |
| Closed | User clicks Close, presses Escape, or clicks the backdrop | EventSource closed, modal removed, page state otherwise unchanged |

### Refresh on close

When the modal closes after a watch that triggered a simulation, the page calls the existing `refreshAll()` JS helper that re-loads standings, fixtures, and predictions. Same flow as after `next-week`. Replay-only watches do not refresh.

### Out of scope for v1 UI

Listed so reviewers see the choices were considered:

- Pause and resume controls
- Scrubbing within the timeline
- Player attribute peek on hover
- Sound effects
- User-selectable speed (only Skip is provided)

## Determinism and seeding

The system is deterministic at three points:

1. **Watched fixture (first watch)**: seed derived as `crc32($season->seed . ':' . $fixture->id)`. The same season and the same fixture always simulate to the same score and the same event list on the first watch.
2. **Re-watch**: pure replay from `match_events`, no simulation.
3. **Tests**: a `SeededRng(int $seed)` is constructed directly and injected, sidestepping the factory.

The factory builds one fresh `SeededRng` per watched match and shares it between `TickMatchSimulator` and `PlayerDecisionEngine`. No shared mutable RNG state crosses match boundaries.

## Testing strategy

Five test files cover the new code:

1. `TickMatchSimulatorTest` (unit): a few hand-crafted scenarios. Same seed produces the same scoreline and the same event list. Goal events sum to the final score. Kickoff is at second 0; full time is at second 5400. Clock is monotonic non-decreasing.
2. `TickEngineRealismTest` (feature): aggregate calibration. 6 pairings times 8 seeds equals 48 matches. Asserts loose bounds on goals per match, shots per match, on-target percentage, conversion percentage, home-vs-away aggregate. Runs in 2 to 3 seconds.
3. `PlayerDecisionEngineTest` (unit): action distribution per zone, attribute weighting, no out-of-zone shots.
4. `MatchStreamControllerTest` (feature): all five paths (replay, simulate-then-replay, 404, 403 historical, 409 stat-played-no-events). Lock contention covered by mocking `Cache::lock` to return false.
5. `WatchUITest` (feature, optional): smoke test that the `/` page renders the Watch button on unplayed fixtures and the Replay button on fixtures with events.

The existing test suite on main (98 tests) must remain green throughout. The full suite plus the five new files should run in well under 30 seconds total.

## File inventory

### New files

```
app/Domain/Contracts/
    WatchableMatchSimulator.php
    MatchSimulatorFactory.php

app/Domain/ValueObjects/
    MatchEvent.php
    MatchResultWithEvents.php

app/Http/Controllers/Api/
    MatchStreamController.php
    MatchEventsController.php           # or method on FixtureController

app/Http/Requests/
    WatchFixtureRequest.php             # validates speed param

app/Http/Resources/
    MatchEventResource.php

app/Models/
    Player.php
    MatchEvent.php

app/Services/Simulation/
    TickMatchSimulator.php
    PlayerDecisionEngine.php
    DefaultMatchSimulatorFactory.php

database/data/
    players.json

database/migrations/
    2026_05_25_000001_create_players_table.php
    2026_05_25_000002_create_match_events_table.php

database/seeders/
    PlayerSeeder.php

tests/Unit/
    TickMatchSimulatorTest.php
    PlayerDecisionEngineTest.php

tests/Feature/
    TickEngineRealismTest.php
    MatchStreamControllerTest.php
    WatchUITest.php                      # optional smoke test
```

### Modified files (additive only)

```
app/Models/Team.php                        # add players() relation
app/Models/Fixture.php                     # add events() relation
app/Http/Resources/FixtureResource.php     # add events_count field
app/Providers/DomainServiceProvider.php    # add MatchSimulatorFactory binding
app/Services/League/LeagueService.php      # add recordWatchedFixture method
database/seeders/DatabaseSeeder.php        # invoke PlayerSeeder
resources/views/league.blade.php           # add Watch button + modal + JS
routes/api.php                             # add two new routes
README.md                                  # add Watch match section
```

## Open questions for the implementation plan

None blocking. Items the implementation plan should decide:

- Exact wording of the SSE row sentences ("Salah's effort saved by Ederson" style). This is presentation, can land late.
- Whether `MatchEventsController` is a new class or a method on `FixtureController`. Lean toward a method on `FixtureController` to keep the controller count modest.
- Final attribute values in `players.json` after calibration runs.
